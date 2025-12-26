<?php

declare(strict_types=1);

namespace App\Service;

use App\Helpers\SqlHelper;
use App\Helpers\WildcardHelper;
use Hyperf\Config\Annotation\Value;
use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use App\Protocol\ConnectionContext;
use App\Protocol\MySql\{Auth, Command, Handshake, Packet, Parser, Prepare, Query, Response};
use function Hyperf\Config\config;

class ProxyService
{
    private ContainerInterface $container;
    private string $logPath;
    private bool $logEnabled;
    private bool $sqlHighlight;
    private array $excludePatterns;
    private array $connections = [];
    private LoggerInterface $logger;
    private LoggerInterface $sqlLogger;
    private LoggerInterface $connectionLogger;

    public function __construct(ContainerInterface $container, LoggerFactory $loggerFactory)
    {
        $this->container = $container;
        $this->logger = $loggerFactory->get('default');
        $this->sqlLogger = $loggerFactory->get('sql');
        $this->connectionLogger = $loggerFactory->get('connection');
        $config = config('proxy', []);

        $this->logEnabled = isset($config['log']['enabled']) ? $config['log']['enabled'] : true;
        $this->logPath = isset($config['log']['path']) ? $config['log']['path'] : BASE_PATH . '/runtime';
        $this->sqlHighlight = isset($config['log']['sql_highlight']) ? $config['log']['sql_highlight'] : true;
        $this->excludePatterns = isset($config['filters']['exclude_patterns']) ? $config['filters']['exclude_patterns'] : [];
    }

    public function onConnect(\Swoole\Server $server, int $fd, int $reactorId): void
    {
        $clientInfo = $server->getClientInfo($fd);
        $clientId = (string) $fd;

        $remoteIp = isset($clientInfo['remote_ip']) ? $clientInfo['remote_ip'] : 'unknown';
        $remotePort = isset($clientInfo['remote_port']) ? $clientInfo['remote_port'] : 0;

        $context = new ConnectionContext(
            $clientId,
            $remoteIp,
            $remotePort
        );

        $this->connections[$clientId] = $context;

        // 发送握手包
        $authData = Auth::generateAuthData(20);
        $handshake = Handshake::createHandshakeV10(
            (int) $clientId,
            $authData,
            'mysql_native_password'
        );

        $server->send($fd, $handshake->toBytes());
    }

    public function onReceive(\Swoole\Server $server, int $fd, int $reactorId, string $data): void
    {
        $clientId = (string) $fd;
        $context = isset($this->connections[$clientId]) ? $this->connections[$clientId] : null;

        if (!$context) {
            return;
        }

        try {
            $packets = Parser::parsePackets($data);

            foreach ($packets as $packet) {
                $this->handlePacket($server, $context, $packet);
            }
        } catch (\Exception $e) {
            // 记录错误日志到 Hyperf 日志组件
            $this->logger->error('处理数据包错误', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function handlePacket(\Swoole\Server $server, ConnectionContext $context, Packet $packet): void
    {
        $command = $packet->getCommand();
        $payload = $packet->getPayload();

        // 处理握手响应
        if ($command === 0 && !isset($payload[1])) {
            // 握手响应，直接转发到目标MySQL
            $this->forwardToTarget($server, $context, $packet);
            return;
        }

        // 处理认证响应
        if ($packet->getSequenceId() === 1 && $context->getMysqlSocket() === null) {
            // 客户端发送认证信息
            $authResponse = Auth::parseHandshakeResponse($packet);

            // 从数据库参数中获取目标MySQL配置
            $targetPort = $context->getTargetPort() !== null ? $context->getTargetPort() : 3306;
            $dsn = "host={$authResponse['username']};port={$targetPort};dbname={$authResponse['database']}";

            // 连接到目标MySQL并转发
            $this->connectToTargetAndForward($server, $context, $packet);
            return;
        }

        // 处理命令
        $parsedCommand = Parser::parseCommand($packet);

        switch ($parsedCommand['type']) {
            case 'query':
                $this->handleQuery($server, $context, $packet, $parsedCommand['sql']);
                break;

            case 'prepare':
                $this->handlePrepare($server, $context, $packet, $parsedCommand['sql']);
                break;

            case 'execute':
                $this->handleExecute($server, $context, $packet, $parsedCommand['data']);
                break;

            case 'quit':
                $this->handleQuit($server, $context);
                break;

            default:
                $this->forwardToTarget($server, $context, $packet);
                break;
        }
    }

    private function handleQuery(\Swoole\Server $server, ConnectionContext $context, Packet $packet, string $sql): void
    {
        $startTime = microtime(true);

        $this->sqlLogger->info('收到SQL查询请求', [
            'client_id' => $context->getClientId(),
            'sql' => $sql,
        ]);

        // 检查排除规则
        if (WildcardHelper::shouldExclude($sql, $this->excludePatterns)) {
            $this->sqlLogger->debug('SQL匹配排除规则，直接转发', [
                'client_id' => $context->getClientId(),
                'sql' => $sql,
                'exclude_patterns' => $this->excludePatterns,
            ]);
            $this->forwardToTarget($server, $context, $packet);
            return;
        }

        // 转发到目标MySQL并获取响应
        $response = $this->forwardToTargetAndGetResponse($server, $context, $packet);

        // 解析响应获取影响行数
        $affectedRows = 0;
        if ($response && Response::isOkPacket($response[0])) {
            $okData = Response::parseOkPacket($response[0]);
            $affectedRows = $okData['affected_rows'];
        }

        // 处理事务
        $this->handleTransaction($context, $sql);

        // 记录日志
        $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($this->logEnabled) {
            $dsnParams = $context->getDsnParams();
            $group = isset($dsnParams['group']) ? $dsnParams['group'] : null;
            $transactionId = $context->isInTransaction() ? $context->getTransactionId() : null;

            $this->sqlLogger->info('SQL查询完成', [
                'client_id' => $context->getClientId(),
                'client_info' => (string) $context,
                'sql' => $sql,
                'group' => $group,
                'transaction_id' => $transactionId,
                'elapsed_ms' => $elapsedMs,
                'affected_rows' => $affectedRows,
            ]);
        }
    }

    private function handlePrepare(\Swoole\Server $server, ConnectionContext $context, Packet $packet, string $sql): void
    {
        $this->sqlLogger->info('收到预处理语句请求', [
            'client_id' => $context->getClientId(),
            'sql' => $sql,
        ]);

        $response = $this->forwardToTargetAndGetResponse($server, $context, $packet);

        // 注册预处理语句
        if ($response && isset($response[0])) {
            $prepareResp = Prepare::parsePrepareResponse($response[0]);
            $stmtId = $prepareResp['statement_id'];
            Parser::registerPreparedStatement($stmtId, $sql);

            $this->sqlLogger->debug('预处理语句注册成功', [
                'client_id' => $context->getClientId(),
                'statement_id' => $stmtId,
                'sql' => $sql,
            ]);
        }
    }

    private function handleExecute(\Swoole\Server $server, ConnectionContext $context, Packet $packet, array $data): void
    {
        $stmtId = $data['statement_id'];
        $sql = Parser::getPreparedStatement($stmtId);

        $this->sqlLogger->info('收到预处理语句执行请求', [
            'client_id' => $context->getClientId(),
            'statement_id' => $stmtId,
            'sql' => $sql,
        ]);

        if ($sql && $this->logEnabled) {
            $dsnParams = $context->getDsnParams();
            $group = isset($dsnParams['group']) ? $dsnParams['group'] : null;
            $transactionId = $context->isInTransaction() ? $context->getTransactionId() : null;

            $this->sqlLogger->info('预处理语句执行', [
                'client_id' => $context->getClientId(),
                'client_info' => (string) $context,
                'sql' => "[EXECUTE] " . $sql,
                'group' => $group,
                'transaction_id' => $transactionId,
                'statement_id' => $stmtId,
            ]);
        }

        $this->forwardToTarget($server, $context, $packet);
    }

    private function handleQuit(\Swoole\Server $server, ConnectionContext $context): void
    {
        $clientId = $context->getClientId();

        $this->connectionLogger->info('客户端请求断开连接', [
            'client_id' => $clientId,
        ]);

        unset($this->connections[$clientId]);

        // 关闭目标MySQL连接
        if ($context->getMysqlSocket()) {
            $context->getMysqlSocket()->close();
            $this->connectionLogger->debug('已关闭目标MySQL连接', [
                'client_id' => $clientId,
            ]);
        }
    }

    private function handleTransaction(ConnectionContext $context, string $sql): void
    {
        $txType = Query::getTransactionType($sql);

        if ($txType === 'start') {
            $context->setInTransaction(true);
        } elseif ($txType === 'commit' || $txType === 'rollback') {
            $context->resetTransaction();
        }
    }

    private function connectToTargetAndForward(\Swoole\Server $server, ConnectionContext $context, Packet $packet): void
    {
        $defaultHost = config('proxy.target.host', '127.0.0.1');
        $defaultPort = config('proxy.target.port', 3307);

        $host = $context->getTargetHost() !== null ? $context->getTargetHost() : $defaultHost;
        $port = (int) ($context->getTargetPort() !== null ? $context->getTargetPort() : $defaultPort);

        $this->connectionLogger->info('开始连接目标MySQL服务器', [
            'client_id' => $context->getClientId(),
            'host' => $host,
            'port' => $port,
        ]);

        try {
            $socket = new Socket(AF_INET, SOCK_STREAM, 0);
            $socket->connect($host, $port);

            $context->setMysqlSocket($socket);

            $this->connectionLogger->info('MySQL连接建立成功', [
                'client_id' => $context->getClientId(),
                'host' => $host,
                'port' => $port,
            ]);

            // 转发认证数据
            $socket->sendAll($packet->toBytes());

            $this->connectionLogger->debug('已发送认证数据', [
                'client_id' => $context->getClientId(),
                'data_length' => strlen($packet->toBytes()),
            ]);

            // 读取响应并转发回客户端
            $response = $this->readAllPackets($socket);
            $server->send((int) $context->getClientId(), $response);

            $this->connectionLogger->debug('认证响应已转发', [
                'client_id' => $context->getClientId(),
                'response_length' => strlen($response),
            ]);
        } catch (\Exception $e) {
            $this->connectionLogger->error('MySQL连接失败', [
                'client_id' => $context->getClientId(),
                'host' => $host,
                'port' => $port,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    private function forwardToTarget(\Swoole\Server $server, ConnectionContext $context, Packet $packet): void
    {
        $socket = $context->getMysqlSocket();

        if (!$socket) {
            $this->connectToTargetAndForward($server, $context, $packet);
            return;
        }

        $packetData = $packet->toBytes();
        $this->connectionLogger->debug('转发数据包到目标MySQL', [
            'client_id' => $context->getClientId(),
            'data_length' => strlen($packetData),
            'command' => $packet->getCommand(),
        ]);

        $socket->sendAll($packetData);

        // 读取响应并转发回客户端
        $response = $this->readAllPackets($socket);
        if ($response !== '') {
            $server->send((int) $context->getClientId(), $response);

            $this->connectionLogger->debug('已转发MySQL响应', [
                'client_id' => $context->getClientId(),
                'response_length' => strlen($response),
            ]);
        }
    }

    private function forwardToTargetAndGetResponse(\Swoole\Server $server, ConnectionContext $context, Packet $packet): array
    {
        $socket = $context->getMysqlSocket();

        if (!$socket) {
            $this->connectToTargetAndForward($server, $context, $packet);
            return [];
        }

        $packetData = $packet->toBytes();
        $this->connectionLogger->debug('转发SQL查询到目标MySQL', [
            'client_id' => $context->getClientId(),
            'data_length' => strlen($packetData),
            'command' => $packet->getCommand(),
        ]);

        $socket->sendAll($packetData);

        $responseData = $this->readAllPackets($socket);
        $packets = Parser::parsePackets($responseData);

        if ($responseData !== '') {
            $server->send((int) $context->getClientId(), $responseData);

            $this->connectionLogger->debug('已转发MySQL响应', [
                'client_id' => $context->getClientId(),
                'response_length' => strlen($responseData),
                'packet_count' => count($packets),
            ]);
        }

        return $packets;
    }

    private function readAllPackets(Socket $socket): string
    {
        $buffer = '';
        $timeout = 1.0;

        // 读取第一个包
        $header = $socket->recvAll(4, $timeout);
        if (strlen($header) !== 4) {
            return $buffer;
        }

        $length = unpack('V', substr($header, 0, 3) . "\x00")[1];
        $payload = $socket->recvAll($length, $timeout);
        $buffer .= $header . $payload;

        // 检查是否还有更多包（ResultSet）
        $numPackets = 1;
        while (true) {
            $nextHeader = $socket->recv(4, $timeout);
            if (strlen($nextHeader) !== 4) {
                break;
            }

            $nextLength = unpack('V', substr($nextHeader, 0, 3) . "\x00")[1];
            $nextPayload = $socket->recvAll($nextLength, $timeout);
            $buffer .= $nextHeader . $nextPayload;

            // EOF包表示结果集结束
            $nextPacket = Packet::fromString($nextHeader . $nextPayload);
            if (Response::isEofPacket($nextPacket)) {
                break;
            }

            $numPackets++;
            if ($numPackets > 10000) { // 防止无限循环
                break;
            }
        }

        return $buffer;
    }

    public function onClose(\Swoole\Server $server, int $fd, int $reactorId): void
    {
        $clientId = (string) $fd;
        if (isset($this->connections[$clientId])) {
            $context = $this->connections[$clientId];

            $this->connectionLogger->info('客户端连接关闭', [
                'client_id' => $clientId,
                'client_ip' => $context->getRemoteIp(),
                'client_port' => $context->getRemotePort(),
            ]);

            // 关闭目标MySQL连接
            if ($context->getMysqlSocket()) {
                $context->getMysqlSocket()->close();
                $this->connectionLogger->debug('已关闭目标MySQL连接', [
                    'client_id' => $clientId,
                ]);
            }

            unset($this->connections[$clientId]);
        }
    }
}
