<?php

declare(strict_types=1);

namespace App\Proxy\Service;

use App\Protocol\MySql\Packet;
use App\Protocol\MySql\Parser;
use App\Protocol\ConnectionContext;
use App\Proxy\Protocol\MySQLHandshake;
use App\Proxy\Auth\ProxyAuthenticator;
use App\Proxy\Executor\BackendExecutor;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * MySQL TLS 代理服务
 * 整合握手、认证和执行的完整代理服务
 */
class MySQLProxyService
{
    private LoggerInterface $logger;
    private MySQLHandshake $handshake;
    private ProxyAuthenticator $authenticator;
    private BackendExecutor $executor;
    private array $connections = [];

    public function __construct(
        LoggerFactory $loggerFactory,
        MySQLHandshake $handshake,
        ProxyAuthenticator $authenticator,
        BackendExecutor $executor
    ) {
        $this->logger = $loggerFactory->get('proxy_service');
        $this->handshake = $handshake;
        $this->authenticator = $authenticator;
        $this->executor = $executor;
    }

    /**
     * 处理客户端连接
     */
    public function handleConnect(ConnectionContext $context): Packet
    {
        $this->logger->info('客户端连接，开始发送握手包', [
            'client_id' => $context->getClientId(),
            'remote_ip' => $context->getClientIp(),
            'remote_port' => $context->getClientPort(),
        ]);

        // 生成并返回服务器握手包
        return $this->handshake->createServerHandshake($context->getThreadId());
    }

    /**
     * 处理客户端数据包
     */
    public function handlePacket(ConnectionContext $context, Packet $packet): ?array
    {
        $command = $packet->getCommand();
        $sequenceId = $packet->getSequenceId();

        $this->logger->debug('处理客户端数据包', [
            'client_id' => $context->getClientId(),
            'command' => $command,
            'sequence_id' => $sequenceId,
        ]);

        // 处理握手响应
        if ($sequenceId === 1) {
            return $this->handleHandshakeResponse($context, $packet);
        }

        // 处理认证响应
        if ($sequenceId === 2 && !$context->isAuthenticated()) {
            return $this->handleAuthentication($context, $packet);
        }

        // 处理正常命令
        if ($context->isAuthenticated()) {
            return $this->handleCommand($context, $packet);
        }

        // 未认证的命令
        $this->logger->warning('收到未认证客户端的命令', [
            'client_id' => $context->getClientId(),
            'command' => $command,
            'sequence_id' => $sequenceId,
        ]);

        return $this->createErrorPackets('Authentication required');
    }

    /**
     * 处理握手响应
     */
    private function handleHandshakeResponse(ConnectionContext $context, Packet $packet): ?array
    {
        $response = $this->handshake->handleClientHandshakeResponse($packet);

        if ($response['type'] === 'ssl_request') {
            $this->logger->info('客户端请求 SSL 连接', [
                'client_id' => $context->getClientId(),
            ]);

            // 标记客户端需要 SSL
            $context->setSslRequested(true);

            // 返回 SSL 切换包
            return [$this->handshake->createSslSwitchPacket()];
        }

        // 如果不是 SSL 请求，说明是直接的认证信息
        return $this->handleAuthentication($context, $packet);
    }

    /**
     * 处理认证
     */
    private function handleAuthentication(ConnectionContext $context, Packet $packet): ?array
    {
        try {
            // 解析认证信息
            $authData = $this->handshake->handleClientHandshakeResponse($packet);

            if ($authData['type'] !== 'auth_response') {
                throw new \RuntimeException('Invalid authentication data');
            }

            $username = $authData['username'];
            $authResponse = $authData['auth_response'];
            $database = $authData['database'] ?? '';

            // 验证代理账号
            $isValid = $this->authenticator->authenticate(
                $username,
                $authResponse,
                $context->getAuthPluginData(),
                $database
            );

            if ($isValid) {
                $this->logger->info('代理认证成功', [
                    'client_id' => $context->getClientId(),
                    'username' => $username,
                    'database' => $database,
                ]);

                // 标记认证成功
                $context->setAuthenticated(true);
                $context->setUsername($username);
                $context->setDatabase($database);

                // 返回认证成功包
                return $this->createAuthSuccessPackets();

            } else {
                $this->logger->warning('代理认证失败', [
                    'client_id' => $context->getClientId(),
                    'username' => $username,
                ]);

                // 返回认证失败包
                return $this->createAuthFailurePackets('Access denied for user');
            }

        } catch (\Exception $e) {
            $this->logger->error('认证过程异常', [
                'client_id' => $context->getClientId(),
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorPackets('Authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * 处理正常命令
     */
    private function handleCommand(ConnectionContext $context, Packet $packet): ?array
    {
        try {
            // 解析命令
            $parsedCommand = Parser::parseCommand($packet);

            $this->logger->debug('处理命令', [
                'client_id' => $context->getClientId(),
                'command_type' => $parsedCommand['type'],
            ]);

            switch ($parsedCommand['type']) {
                case 'query':
                    return $this->handleQuery($context, $parsedCommand['sql']);

                case 'quit':
                    $this->handleQuit($context);
                    return null; // 连接将关闭

                default:
                    $this->logger->debug('不支持的命令类型', [
                        'client_id' => $context->getClientId(),
                        'command_type' => $parsedCommand['type'],
                    ]);
                    return $this->createErrorPackets('Command not supported');
            }

        } catch (\Exception $e) {
            $this->logger->error('处理命令异常', [
                'client_id' => $context->getClientId(),
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorPackets('Command execution failed: ' . $e->getMessage());
        }
    }

    /**
     * 处理查询命令
     */
    private function handleQuery(ConnectionContext $context, string $sql): array
    {
        $this->logger->info('执行 SQL 查询', [
            'client_id' => $context->getClientId(),
            'username' => $context->getUsername(),
            'sql' => $sql,
        ]);

        // 使用后端执行器执行 SQL
        return $this->executor->execute($sql);
    }

    /**
     * 处理退出命令
     */
    private function handleQuit(ConnectionContext $context): void
    {
        $this->logger->info('客户端请求断开连接', [
            'client_id' => $context->getClientId(),
            'username' => $context->getUsername(),
        ]);

        // 清理上下文
        $context->setAuthenticated(false);
    }

    /**
     * 创建认证成功响应包
     */
    private function createAuthSuccessPackets(): array
    {
        // MySQL OK 包 (认证成功)
        $payload = chr(0x00); // OK packet header
        $payload .= $this->encodeLength(0); // affected_rows
        $payload .= $this->encodeLength(0); // last_insert_id
        $payload .= pack('v', 0); // status_flags
        $payload .= pack('v', 0); // warnings

        return [Packet::create(2, $payload)];
    }

    /**
     * 创建认证失败响应包
     */
    private function createAuthFailurePackets(string $message): array
    {
        // MySQL ERR 包 (认证失败)
        $payload = chr(0xff); // Error packet header
        $payload .= pack('v', 1045); // error code (ER_ACCESS_DENIED_ERROR)
        $payload .= '#'; // sql_state_marker
        $payload .= '28000'; // sql_state
        $payload .= $message; // error message

        return [Packet::create(2, $payload)];
    }

    /**
     * 创建错误响应包
     */
    private function createErrorPackets(string $message): array
    {
        $payload = chr(0xff); // Error packet header
        $payload .= pack('v', 2000); // error code
        $payload .= '#'; // sql_state_marker
        $payload .= 'HY000'; // sql_state
        $payload .= $message; // error message

        return [Packet::create(0, $payload)];
    }

    /**
     * 编码长度整型
     */
    private function encodeLength(int $value): string
    {
        if ($value < 251) {
            return chr($value);
        } elseif ($value < 65536) {
            return chr(0xfc) . pack('v', $value);
        } elseif ($value < 16777216) {
            return chr(0xfd) . substr(pack('V', $value), 0, 3);
        } else {
            return chr(0xfe) . pack('V', $value);
        }
    }

    /**
     * 获取连接池统计信息
     */
    public function getPoolStats(): array
    {
        return $this->executor->getPoolStats();
    }

    /**
     * 关闭服务
     */
    public function close(): void
    {
        $this->executor->close();
        $this->logger->info('MySQL 代理服务已关闭');
    }

    // ========== Swoole 事件处理器 ==========

    /**
     * 处理连接事件
     */
    public function onConnect(\Swoole\Server $server, int $fd, int $reactorId): void
    {
        $this->logger->info('=== 新客户端连接 ===', [
            'fd' => $fd,
            'reactor_id' => $reactorId,
            'pid' => getmypid(),
        ]);

        $clientInfo = $server->getClientInfo($fd);
        $clientId = $fd;

        $remoteIp = isset($clientInfo['remote_ip']) ? $clientInfo['remote_ip'] : 'unknown';
        $remotePort = isset($clientInfo['remote_port']) ? $clientInfo['remote_port'] : 0;

        $this->logger->info('创建连接上下文', [
            'client_id' => $clientId,
            'remote_ip' => $remoteIp,
            'remote_port' => $remotePort,
        ]);

        // 创建连接上下文
        $context = new ConnectionContext($clientId, $remoteIp, $remotePort);
        $context->setAuthPluginData($this->handshake->generateAuthPluginData());

        $this->connections[$clientId] = $context;

        // 发送服务器握手包
        try {
            $handshakePacket = $this->handleConnect($context);
            $server->send($fd, $handshakePacket->toBytes());

            $this->logger->info('握手包已发送', [
                'client_id' => $clientId,
                'packet_length' => strlen($handshakePacket->toBytes()),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('发送握手包失败', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            $server->close($fd);
        }
    }

    /**
     * 处理接收数据事件
     */
    public function onReceive(\Swoole\Server $server, int $fd, int $reactorId, string $data): void
    {
        $this->logger->info('=== 收到客户端数据 ===', [
            'fd' => $fd,
            'reactor_id' => $reactorId,
            'data_length' => strlen($data),
            'pid' => getmypid(),
        ]);

        $clientId = (string) $fd;
        $context = isset($this->connections[$clientId]) ? $this->connections[$clientId] : null;

        if (!$context) {
            $this->logger->warning('收到未知客户端的数据', [
                'client_id' => $clientId,
                'data_length' => strlen($data),
            ]);
            $server->close($fd);
            return;
        }

        try {
            // 解析数据包
            $packets = Parser::parsePackets($data);

            $this->logger->debug('解析数据包成功', [
                'client_id' => $clientId,
                'packet_count' => count($packets),
            ]);

            // 处理每个数据包
            foreach ($packets as $packet) {
                $responsePackets = $this->handlePacket($context, $packet);

                if ($responsePackets === null) {
                    // 连接将关闭
                    $server->close($fd);
                    return;
                }

                // 发送响应包
                foreach ($responsePackets as $responsePacket) {
                    $server->send($fd, $responsePacket->toBytes());
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('处理数据包异常', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 发送错误响应
            $errorPackets = $this->createErrorPackets('Internal server error');
            foreach ($errorPackets as $packet) {
                $server->send($fd, $packet->toBytes());
            }
        }
    }

    /**
     * 处理连接关闭事件
     */
    public function onClose(\Swoole\Server $server, int $fd, int $reactorId): void
    {
        $clientId = (string) $fd;

        $this->logger->info('客户端连接关闭', [
            'client_id' => $clientId,
            'reactor_id' => $reactorId,
        ]);

        if (isset($this->connections[$clientId])) {
            $context = $this->connections[$clientId];

            $this->logger->info('清理连接上下文', [
                'client_id' => $clientId,
                'username' => $context->getUsername(),
                'authenticated' => $context->isAuthenticated(),
            ]);

            unset($this->connections[$clientId]);
        }
    }
}
