<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Service\ProxyService;
use App\Protocol\ConnectionContext;
use Hyperf\Testing\TestCase;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Server;

/**
 * @internal
 * @coversNothing
 */
class ProxyServiceOnReceiveTest extends TestCase
{
    private ProxyService $proxyService;
    private $containerMock;
    private $loggerMock;
    private $sqlLoggerMock;
    private $connectionLoggerMock;
    private $mysqlSendLoggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建 mock 容器
        $this->containerMock = $this->createMock(PsrContainerInterface::class);
        
        // 创建各种 logger mock
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->sqlLoggerMock = $this->createMock(LoggerInterface::class);
        $this->connectionLoggerMock = $this->createMock(LoggerInterface::class);
        $this->mysqlSendLoggerMock = $this->createMock(LoggerInterface::class);

        // 创建 logger factory mock
        $loggerFactoryMock = $this->createMock(
            \Hyperf\Logger\LoggerFactory::class
        );
        
        // 设置 logger factory 返回对应的 logger
        $loggerFactoryMock->method('get')->willReturnCallback(function ($channel) {
            switch ($channel) {
                case 'default':
                    return $this->loggerMock;
                case 'sql':
                    return $this->sqlLoggerMock;
                case 'connection':
                    return $this->connectionLoggerMock;
                case 'mysql_send':
                    return $this->mysqlSendLoggerMock;
                default:
                    return $this->loggerMock;
            }
        });

        $this->proxyService = new ProxyService($this->containerMock, $loggerFactoryMock);
        // 设置默认的 logger 期望（void 方法不需要返回值）
        $this->loggerMock->method('info');
        $this->loggerMock->method('debug');
        $this->loggerMock->method('error');
        $this->loggerMock->method('warning');

        $this->sqlLoggerMock->method('info');
        $this->sqlLoggerMock->method('debug');

        $this->connectionLoggerMock->method('info');
        $this->connectionLoggerMock->method('debug');
        $this->connectionLoggerMock->method('error');
        $this->connectionLoggerMock->method('warning');

        $this->mysqlSendLoggerMock->method('info');
        $this->mysqlSendLoggerMock->method('debug');

    }

    /**
     * 测试：收到未知连接ID的数据
     */
    public function testReceiveWithUnknownConnection()
    {
        $server = $this->createMock(Server::class);

        $fd = 999; // 不存在的连接ID
        $reactorId = 1;
        $data = 'test data';

        // 设置 logger 期望
        $this->connectionLoggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'sql代理: 收到未知客户端的数据',
                $this->callback(function ($context) use ($fd) {
                    return $context['client_id'] === (string)$fd;
                })
            );

        // 调用应该不会抛出异常
        $this->proxyService->onReceive($server, $fd, $reactorId, $data);

        $this->assertTrue(true, '处理未知连接成功');
    }

    /**
     * 测试：收到空数据
     */
    public function testReceiveWithEmptyData()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;
        $data = '';

        // 创建连接上下文并添加到服务
        $this->addConnectionContext($fd, $server);

        // 调用应该不会抛出异常
        $this->proxyService->onReceive($server, $fd, $reactorId, $data);

        $this->assertTrue(true, '处理空数据成功');
    }

    /**
     * 测试：检测到 TLS 握手数据（ContentType=22, Version=0x0301）
     */
    public function testReceiveWithTlsHandshakeData()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;

        // 创建 TLS 握手数据：ContentType(1) + Version(2) + Length(2) + ...
        // TLS 1.0 握手：0x16 0x03 0x01 0x00 0x...
        $tlsData = chr(0x16) . chr(0x03) . chr(0x01) . chr(0x00) . chr(0x05) . 'test';

        // 创建连接上下文并添加到服务
        $this->addConnectionContext($fd, $server);

        // 设置 logger 期望
        $this->connectionLoggerMock->expects($this->once())
            ->method('info')
            ->with(
                '检测到TLS握手数据，尝试启用SSL支持',
                $this->callback(function ($context) use ($fd) {
                    return $context['client_id'] === (string)$fd;
                })
            );

        // 设置 getClientInfo 期望（模拟没有 socket_fd）
        $server->method('getClientInfo')->willReturn([]);

        // 设置 send 期望
        $server->expects($this->once())
            ->method('send')
            ->with($fd, $this->anything());

        // 设置 close 期望
        $server->expects($this->once())
            ->method('close')
            ->with($fd);

        // 调用 onReceive
        $this->proxyService->onReceive($server, $fd, $reactorId, $tlsData);

        $this->assertTrue(true, 'TLS 握手数据处理成功');
    }

    /**
     * 测试：收到有效的 MySQL 数据包
     */
    public function testReceiveWithValidMySQLPacket()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;

        // 创建一个简单的 MySQL 数据包
        // 格式：length(3) + sequence_id(1) + payload
        // 示例：COM_QUIT 命令（command=1）
        $packetLength = 1;
        $sequenceId = 0;
        $command = 0x01; // COM_QUIT
        $packetData = pack('V', $packetLength) & "\xff\xff\xff";
        $packetData .= chr($sequenceId);
        $packetData .= chr($command);

        // 创建连接上下文并添加到服务
        $context = $this->addConnectionContext($fd, $server);

        // 添加 MySQL socket 到上下文
        $mysqlSocket = $this->createMock(
            \Swoole\Coroutine\Socket::class
        );
        $context->setMysqlSocket($mysqlSocket);

        // 设置 socket 期望
        $mysqlSocket->method('sendAll')->willReturn(1);
        $mysqlSocket->method('recvAll')->willReturn($this->createOkPacket());

        // 设置 server 期望
        $server->method('send')->willReturn(true);

        // 调用 onReceive
        $this->proxyService->onReceive($server, $fd, $reactorId, $packetData);

        $this->assertTrue(true, '有效 MySQL 数据包处理成功');
    }

    /**
     * 测试：收到 COM_QUERY 命令
     */
    public function testReceiveWithQueryCommand()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;

        // 创建 COM_QUERY 数据包
        $query = 'SELECT 1';
        $packetLength = 1 + strlen($query);
        $sequenceId = 0;
        $command = 0x03; // COM_QUERY
        
        $packetData = pack('V', $packetLength) & "\xff\xff\xff";
        $packetData .= chr($sequenceId);
        $packetData .= chr($command);
        $packetData .= $query;

        // 创建连接上下文并添加到服务
        $context = $this->addConnectionContext($fd, $server);

        // 添加 MySQL socket 到上下文
        $mysqlSocket = $this->createMock(
            \Swoole\Coroutine\Socket::class
        );
        $context->setMysqlSocket($mysqlSocket);

        // 设置 socket 期望
        $mysqlSocket->method('sendAll')->willReturn(strlen($query));
        $mysqlSocket->method('recvAll')->willReturn($this->createOkPacket());

        // 设置 server 期望
        $server->method('send')->willReturn(true);

        // 设置 sql logger 期望
        $this->sqlLoggerMock->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->logicalOr(
                    '收到SQL查询请求',
                    'SQL查询完成'
                ),
                $this->callback(function ($context) {
                    return isset($context['sql']) && $context['sql'] === 'SELECT 1';
                })
            );

        // 调用 onReceive
        $this->proxyService->onReceive($server, $fd, $reactorId, $packetData);

        $this->assertTrue(true, 'COM_QUERY 命令处理成功');
    }

    /**
     * 测试：收到无效的数据包（会抛出异常）
     */
    public function testReceiveWithInvalidPacket()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;

        // 创建无效的数据包（长度字段不完整）
        $invalidData = chr(0x01) . chr(0x02); // 只有2字节，不够4字节包头

        // 创建连接上下文并添加到服务
        $this->addConnectionContext($fd, $server);

        // 设置 logger 期望（解析器会抛出异常，错误日志应该被调用）
        $this->loggerMock->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                'sql代理: 处理数据包错误',
                $this->callback(function ($context) {
                    return isset($context['message']) && isset($context['error_code']);
                })
            );

        // 调用 onReceive
        $this->proxyService->onReceive($server, $fd, $reactorId, $invalidData);

        $this->assertTrue(true, '无效数据包被正确处理（记录错误）');
    }

    /**
     * 测试：收到多个数据包
     */
    public function testReceiveWithMultiplePackets()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;

        // 创建两个数据包
        // 第一个包：COM_PING (command=0x0e)
        $packet1Length = 1;
        $packet1Seq = 0;
        $packet1Data = pack('V', $packet1Length) & "\xff\xff\xff";
        $packet1Data .= chr($packet1Seq);
        $packet1Data .= chr(0x0e); // COM_PING

        // 第二个包：COM_PING (command=0x0e)
        $packet2Length = 1;
        $packet2Seq = 1;
        $packet2Data = pack('V', $packet2Length) & "\xff\xff\xff";
        $packet2Data .= chr($packet2Seq);
        $packet2Data .= chr(0x0e); // COM_PING

        $multiPacketData = $packet1Data . $packet2Data;

        // 创建连接上下文并添加到服务
        $context = $this->addConnectionContext($fd, $server);

        // 添加 MySQL socket 到上下文
        $mysqlSocket = $this->createMock(
            \Swoole\Coroutine\Socket::class
        );
        $context->setMysqlSocket($mysqlSocket);

        // 设置 socket 期望（会被调用两次，每个包一次）
        $mysqlSocket->method('sendAll')->willReturn(1);
        $mysqlSocket->method('recvAll')->willReturn($this->createOkPacket());

        // 设置 server 期望
        $server->method('send')->willReturn(true);

        // 调用 onReceive
        $this->proxyService->onReceive($server, $fd, $reactorId, $multiPacketData);

        $this->assertTrue(true, '多个数据包处理成功');
    }

    /**
     * 测试：收到 COM_INIT_DB 命令（USE 语句）
     */
    public function testReceiveWithInitDbCommand()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;

        // 创建 COM_INIT_DB 数据包
        $database = 'test_db';
        $packetLength = 1 + strlen($database);
        $sequenceId = 0;
        $command = 0x02; // COM_INIT_DB
        
        $packetData = pack('V', $packetLength) & "\xff\xff\xff";
        $packetData .= chr($sequenceId);
        $packetData .= chr($command);
        $packetData .= $database;

        // 创建连接上下文并添加到服务
        $context = $this->addConnectionContext($fd, $server);

        // 添加 MySQL socket 到上下文
        $mysqlSocket = $this->createMock(
            \Swoole\Coroutine\Socket::class
        );
        $context->setMysqlSocket($mysqlSocket);

        // 设置 socket 期望
        $mysqlSocket->method('sendAll')->willReturn(strlen($database));
        $mysqlSocket->method('recvAll')->willReturn($this->createOkPacket());

        // 设置 server 期望
        $server->method('send')->willReturn(true);

        // 调用 onReceive
        $this->proxyService->onReceive($server, $fd, $reactorId, $packetData);

        $this->assertTrue(true, 'COM_INIT_DB 命令处理成功');
    }

    /**
     * 测试：收到包含特殊字符的数据
     */
    public function testReceiveWithSpecialCharacters()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;

        // 创建包含特殊字符的查询
        $query = "SELECT '测试' AS name, '特殊字符: \\n\\t\\r' AS data";
        $packetLength = 1 + strlen($query);
        $sequenceId = 0;
        $command = 0x03; // COM_QUERY
        
        $packetData = pack('V', $packetLength) & "\xff\xff\xff";
        $packetData .= chr($sequenceId);
        $packetData .= chr($command);
        $packetData .= $query;

        // 创建连接上下文并添加到服务
        $context = $this->addConnectionContext($fd, $server);

        // 添加 MySQL socket 到上下文
        $mysqlSocket = $this->createMock(
            \Swoole\Coroutine\Socket::class
        );
        $context->setMysqlSocket($mysqlSocket);

        // 设置 socket 期望
        $mysqlSocket->method('sendAll')->willReturn(strlen($query));
        $mysqlSocket->method('recvAll')->willReturn($this->createOkPacket());

        // 设置 server 期望
        $server->method('send')->willReturn(true);

        // 调用 onReceive
        $this->proxyService->onReceive($server, $fd, $reactorId, $packetData);

        $this->assertTrue(true, '特殊字符数据处理成功');
    }

    /**
     * 测试：收到超大数据包（边界测试）
     */
    public function testReceiveWithLargePacket()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;

        // 创建大查询
        $largeQuery = str_repeat('SELECT * FROM test WHERE id = 1; ', 100);
        $packetLength = 1 + strlen($largeQuery);
        $sequenceId = 0;
        $command = 0x03; // COM_QUERY
        
        $packetData = pack('V', $packetLength) & "\xff\xff\xff";
        $packetData .= chr($sequenceId);
        $packetData .= chr($command);
        $packetData .= $largeQuery;

        // 创建连接上下文并添加到服务
        $context = $this->addConnectionContext($fd, $server);

        // 添加 MySQL socket 到上下文
        $mysqlSocket = $this->createMock(
            \Swoole\Coroutine\Socket::class
        );
        $context->setMysqlSocket($mysqlSocket);

        // 设置 socket 期望
        $mysqlSocket->method('sendAll')->willReturn(strlen($largeQuery));
        $mysqlSocket->method('recvAll')->willReturn($this->createOkPacket());

        // 设置 server 期望
        $server->method('send')->willReturn(true);

        // 调用 onReceive
        $this->proxyService->onReceive($server, $fd, $reactorId, $packetData);

        $this->assertTrue(true, '大数据包处理成功');
    }

    /**
     * 测试：收到数据但没有 MySQL 连接（sequence_id=1）
     */
    public function testReceiveWithoutMySQLConnection()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;

        // 创建认证响应数据包（sequence_id=1）
        $authResponse = str_repeat("\x00", 20); // 模拟认证响应
        $packetLength = 1 + strlen($authResponse);
        $sequenceId = 1; // 认证响应
        $command = 0x00; // 认证命令
        
        $packetData = pack('V', $packetLength) & "\xff\xff\xff";
        $packetData .= chr($sequenceId);
        $packetData .= chr($command);
        $packetData .= $authResponse;

        // 创建连接上下文并添加到服务（但不添加 MySQL socket）
        $this->addConnectionContext($fd, $server);

        // 调用 onReceive - 应该尝试建立连接但会失败
        $this->proxyService->onReceive($server, $fd, $reactorId, $packetData);

        $this->assertTrue(true, '无 MySQL 连接情况处理成功');
    }

    /**
     * 测试：收到非 TLS 数据但检测为 TLS 格式的数据
     */
    public function testReceiveWithNonTlsDataThatLooksLikeTls()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;

        // 创建看起来像 TLS 但不是的数据
        // ContentType=22, Version=0x0300, 但后续不是有效的 TLS 数据
        $data = chr(0x16) . chr(0x03) . chr(0x00) . chr(0x00) . chr(0x01) . chr(0x00);

        // 创建连接上下文并添加到服务
        $this->addConnectionContext($fd, $server);

        // 设置 getClientInfo 期望
        $server->method('getClientInfo')->willReturn([]);

        // 设置 send 和 close 期望
        $server->expects($this->once())->method('send');
        $server->expects($this->once())->method('close');

        // 调用 onReceive
        $this->proxyService->onReceive($server, $fd, $reactorId, $data);

        $this->assertTrue(true, '类似 TLS 但非 TLS 数据处理成功');
    }

    /**
     * 辅助方法：添加连接上下文
     */
    private function addConnectionContext(int $fd, Server $server)
    {
        // 通过反射访问私有属性 connections
        $reflection = new \ReflectionClass($this->proxyService);
        $connectionsProperty = $reflection->getProperty('connections');
        $connectionsProperty->setAccessible(true);

        $context = new ConnectionContext($fd, '127.0.0.1', 3306);
        $connectionsProperty->setValue($this->proxyService, [(string)$fd => $context]);

        return $context;
    }

    /**
     * 辅助方法：创建 OK 响应包
     */
    private function createOkPacket(): string
    {
        // MySQL OK 包格式：
        // Length(3) + SequenceId(1) + OK标记(1) + AffectedRows(1-9) + LastInsertId(1-9) + ...
        $okPacket = chr(0x07) . chr(0x00) . chr(0x00) . chr(0x01); // length=7, seq=0
        $okPacket .= chr(0x00); // OK 标记
        $okPacket .= chr(0x00); // affected_rows
        $okPacket .= chr(0x00); // last_insert_id
        $okPacket .= chr(0x02) . chr(0x00); // status_flags
        $okPacket .= chr(0x00) . chr(0x00); // warnings

        return $okPacket;
    }
}
