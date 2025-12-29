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

        // 记录ProxyService初始化信息
        $this->logger->info('ProxyService初始化完成', [
            'log_path' => $this->logPath,
            'log_enabled' => $this->logEnabled,
            'sql_highlight' => $this->sqlHighlight,
            'exclude_patterns' => $this->excludePatterns,
        ]);

        $this->connectionLogger->info('Connection Logger初始化成功');
        $this->sqlLogger->info('SQL Logger初始化成功');
    }

    public function onConnect(\Swoole\Server $server, int $fd, int $reactorId): void
    {
        $this->logger->info('=== onConnect 被调用 ===', [
            'fd' => $fd,
            'reactor_id' => $reactorId,
            'pid' => getmypid(),
        ]);

        $this->connectionLogger->info('=== onConnect 被调用 ===', [
            'fd' => $fd,
            'reactor_id' => $reactorId,
            'pid' => getmypid(),
        ]);

        $clientInfo = $server->getClientInfo($fd);
        $clientId = (string) $fd;

        $remoteIp = isset($clientInfo['remote_ip']) ? $clientInfo['remote_ip'] : 'unknown';
        $remotePort = isset($clientInfo['remote_port']) ? $clientInfo['remote_port'] : 0;

        $this->connectionLogger->info('客户端连接请求', [
            'client_id' => $clientId,
            'remote_ip' => $remoteIp,
            'remote_port' => $remotePort,
            'reactor_id' => $reactorId,
            'client_info' => $clientInfo,
        ]);

        $this->logger->info('创建连接上下文', [
            'client_id' => $clientId,
        ]);

        $context = new ConnectionContext(
            $clientId,
            $remoteIp,
            $remotePort
        );

        $this->connections[$clientId] = $context;

        // 连接到目标MySQL并获取握手包
        $defaultHost = config('proxy.target.host', '127.0.0.1');
        $defaultPort = config('proxy.target.port', 3307);

        $host = $context->getTargetHost() !== null ? $context->getTargetHost() : $defaultHost;
        $port = (int) ($context->getTargetPort() !== null ? $context->getTargetPort() : $defaultPort);

        try {
            $this->connectionLogger->debug('创建MySQL连接以获取握手包', [
                'client_id' => $clientId,
                'host' => $host,
                'port' => $port,
            ]);

            $socket = new Socket(AF_INET, SOCK_STREAM, 0);
            $socket->connect($host, $port);

            // 读取MySQL握手包
            $mysqlHandshakeData = $this->readAllPackets($socket);

            $this->connectionLogger->debug('收到MySQL握手包', [
                'client_id' => $clientId,
                'handshake_length' => strlen($mysqlHandshakeData),
                'handshake_hex' => bin2hex(substr($mysqlHandshakeData, 0, 64)),
            ]);

            // 保存MySQL socket到上下文
            $context->setMysqlSocket($socket);

            // 直接转发MySQL的握手包
            $sendResult = $server->send($fd, $mysqlHandshakeData);

            $this->connectionLogger->info('MySQL握手包已转发', [
                'client_id' => $clientId,
                'handshake_length' => strlen($mysqlHandshakeData),
                'send_result' => $sendResult,
            ]);
        } catch (\Exception $e) {
            $this->connectionLogger->error('获取MySQL握手包失败', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ]);
            throw $e;
        }
    }

    public function onReceive(\Swoole\Server $server, int $fd, int $reactorId, string $data): void
    {
        $this->logger->info('=== onReceive 被调用 ===', [
            'fd' => $fd,
            'reactor_id' => $reactorId,
            'data_length' => strlen($data),
            'pid' => getmypid(),
        ]);

        $this->connectionLogger->info('=== onReceive 被调用 ===', [
            'fd' => $fd,
            'reactor_id' => $reactorId,
            'data_length' => strlen($data),
            'pid' => getmypid(),
        ]);

        $clientId = (string) $fd;
        $context = isset($this->connections[$clientId]) ? $this->connections[$clientId] : null;

        if (!$context) {
            $this->connectionLogger->warning('sql代理: 收到未知客户端的数据', [
                'client_id' => $clientId,
                'data_length' => strlen($data),
                'data_hex' => bin2hex(substr($data, 0, 64)),
            ]);
            return;
        }

        $this->connectionLogger->info('收到客户端数据', [
            'client_id' => $clientId,
            'data_length' => strlen($data),
            'data_hex' => bin2hex($data),
            'data_ascii' => $this->toAscii($data),
        ]);

        try {
            $packets = Parser::parsePackets($data);

            $this->connectionLogger->debug('解析数据包成功', [
                'client_id' => $clientId,
                'packet_count' => count($packets),
            ]);

            foreach ($packets as $index => $packet) {
                $this->connectionLogger->debug('处理数据包', [
                    'client_id' => $clientId,
                    'packet_index' => $index,
                    'command' => $packet->getCommand(),
                    'sequence_id' => $packet->getSequenceId(),
                ]);
                $this->handlePacket($server, $context, $packet);
            }
        } catch (\Exception $e) {
            // 记录错误日志到 Hyperf 日志组件
            $this->logger->error('sql代理: 处理数据包错误', [
                'message' => $e->getMessage(),
                'error_code' => $e->getCode(),
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
        $sequenceId = $packet->getSequenceId();

        $this->connectionLogger->debug('开始处理数据包', [
            'client_id' => $context->getClientId(),
            'command' => $command,
            'sequence_id' => $sequenceId,
            'payload_length' => strlen($payload),
            'mysql_socket_exists' => $context->getMysqlSocket() !== null,
        ]);

        // 处理握手响应
        if ($command === 0 && !isset($payload[1])) {
            $this->connectionLogger->debug('检测到握手响应，将转发到目标MySQL', [
                'client_id' => $context->getClientId(),
                'sequence_id' => $sequenceId,
            ]);
            // 握手响应，直接转发到目标MySQL
            $this->forwardToTarget($server, $context, $packet);
            return;
        }

        // 处理认证响应
        if ($packet->getSequenceId() === 1 && $context->getMysqlSocket() === null) {
            $this->connectionLogger->info('检测到客户端认证响应，准备连接目标MySQL', [
                'client_id' => $context->getClientId(),
                'sequence_id' => $sequenceId,
                'packet_command' => $command,
                'payload_length' => strlen($payload),
            ]);

            // 客户端发送认证信息
            try {
                $authResponse = Auth::parseHandshakeResponse($packet);

                $this->connectionLogger->info('解析客户端认证信息成功', [
                    'client_id' => $context->getClientId(),
                    'username' => $authResponse['username'] ?? 'unknown',
                    'database' => $authResponse['database'] ?? 'unknown',
                    'auth_plugin' => $authResponse['auth_plugin_name'] ?? 'unknown',
                    'charset' => $authResponse['charset'] ?? 'unknown',
                    'capabilities_hex' => sprintf('0x%08x', $authResponse['capabilities'] ?? 0),
                    'capabilities_dec' => $authResponse['capabilities'] ?? 0,
                    'max_packet_size' => $authResponse['max_packet_size'] ?? 0,
                    'auth_response_length' => strlen($authResponse['auth_response'] ?? ''),
                    'payload_hex' => bin2hex($payload),
                ]);
            } catch (\Exception $e) {
                $this->connectionLogger->error('sql代理: 解析客户端认证信息失败', [
                    'client_id' => $context->getClientId(),
                    'error' => $e->getMessage(),
                    'payload_hex' => bin2hex($payload),
                    'payload_length' => strlen($payload),
                ]);
                throw $e;
            }

            // 从数据库参数中获取目标MySQL配置
            $targetPort = $context->getTargetPort() !== null ? $context->getTargetPort() : 3306;
            $dsn = "host={$authResponse['username']};port={$targetPort};dbname={$authResponse['database']}";

            $this->connectionLogger->debug('构建目标DSN', [
                'client_id' => $context->getClientId(),
                'dsn' => $dsn,
            ]);

            // 连接到目标MySQL并转发
            $this->connectToTargetAndForward($server, $context, $packet);
            return;
        }

        // 处理命令
        $parsedCommand = Parser::parseCommand($packet);

        $this->connectionLogger->debug('解析命令类型', [
            'client_id' => $context->getClientId(),
            'command_type' => $parsedCommand['type'],
        ]);

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
                $this->connectionLogger->debug('未知命令类型，直接转发', [
                    'client_id' => $context->getClientId(),
                    'command_type' => $parsedCommand['type'],
                ]);
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
        $socket = $context->getMysqlSocket();

        if (!$socket) {
            $this->connectionLogger->error('MySQL连接不存在', [
                'client_id' => $context->getClientId(),
            ]);
            throw new \RuntimeException('MySQL连接不存在');
        }

        try {
            // 转发认证数据
            $authData = $packet->toBytes();
            $this->connectionLogger->debug('准备发送认证数据', [
                'client_id' => $context->getClientId(),
                'data_length' => strlen($authData),
                'data_hex' => bin2hex(substr($authData, 0, 32)),
            ]);

            $sendResult = $socket->sendAll($authData);

            $this->connectionLogger->debug('已发送认证数据', [
                'client_id' => $context->getClientId(),
                'data_length' => strlen($authData),
                'sent_bytes' => $sendResult,
            ]);

            // 读取响应并转发回客户端
            $this->connectionLogger->debug('开始读取MySQL响应', [
                'client_id' => $context->getClientId(),
            ]);

            $response = $this->readAllPackets($socket);

            $this->connectionLogger->debug('MySQL响应读取完成，准备转发回客户端', [
                'client_id' => $context->getClientId(),
                'response_length' => strlen($response),
            ]);

            // 解析MySQL响应，检查是否是错误包
            $responsePackets = Parser::parsePackets($response);
            if (!empty($responsePackets) && Response::isErrorPacket($responsePackets[0])) {
                $errorData = Response::parseErrorPacket($responsePackets[0]);
                $this->connectionLogger->error('MySQL认证失败', [
                    'client_id' => $context->getClientId(),
                    'error_code' => $errorData['error_code'],
                    'sql_state' => $errorData['sql_state'],
                    'error_message' => $errorData['error_message'],
                ]);
            }

            // 直接转发MySQL响应（保持原始sequence_id）
            $server->send((int) $context->getClientId(), $response);

            $this->connectionLogger->info('认证响应已转发', [
                'client_id' => $context->getClientId(),
                'response_length' => strlen($response),
            ]);
        } catch (\Swoole\Coroutine\Socket\Exception $e) {
            $this->connectionLogger->error('sql代理: MySQL Socket操作失败', [
                'client_id' => $context->getClientId(),
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'error_string' => socket_strerror($e->getCode()),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->connectionLogger->error('sql代理: 认证失败', [
                'client_id' => $context->getClientId(),
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function forwardToTarget(\Swoole\Server $server, ConnectionContext $context, Packet $packet): void
    {
        $socket = $context->getMysqlSocket();

        if (!$socket) {
            $this->connectionLogger->debug('MySQL连接不存在，尝试建立连接', [
                'client_id' => $context->getClientId(),
            ]);
            $this->connectToTargetAndForward($server, $context, $packet);
            return;
        }

        $packetData = $packet->toBytes();
        $this->connectionLogger->debug('转发数据包到目标MySQL', [
            'client_id' => $context->getClientId(),
            'data_length' => strlen($packetData),
            'command' => $packet->getCommand(),
            'data_hex' => bin2hex(substr($packetData, 0, 32)),
        ]);

        $sendResult = $socket->sendAll($packetData);

        $this->connectionLogger->debug('数据发送完成', [
            'client_id' => $context->getClientId(),
            'sent_bytes' => $sendResult,
        ]);

        // 读取响应并转发回客户端
        $response = $this->readAllPackets($socket);
        if ($response !== '') {
            // 直接转发MySQL响应（保持原始sequence_id）
            $server->send((int) $context->getClientId(), $response);

            $this->connectionLogger->debug('已转发MySQL响应', [
                'client_id' => $context->getClientId(),
                'response_length' => strlen($response),
            ]);
        } else {
            $this->connectionLogger->warning('sql代理: MySQL响应为空', [
                'client_id' => $context->getClientId(),
            ]);
        }
    }

    private function forwardToTargetAndGetResponse(\Swoole\Server $server, ConnectionContext $context, Packet $packet): array
    {
        $socket = $context->getMysqlSocket();

        if (!$socket) {
            $this->connectionLogger->debug('MySQL连接不存在，尝试建立连接', [
                'client_id' => $context->getClientId(),
            ]);
            $this->connectToTargetAndForward($server, $context, $packet);
            return [];
        }

        $packetData = $packet->toBytes();
        $this->connectionLogger->debug('转发SQL查询到目标MySQL', [
            'client_id' => $context->getClientId(),
            'data_length' => strlen($packetData),
            'command' => $packet->getCommand(),
            'data_hex' => bin2hex(substr($packetData, 0, 32)),
        ]);

        $sendResult = $socket->sendAll($packetData);

        $this->connectionLogger->debug('SQL查询发送完成', [
            'client_id' => $context->getClientId(),
            'sent_bytes' => $sendResult,
        ]);

        $responseData = $this->readAllPackets($socket);

        $this->connectionLogger->debug('开始解析MySQL响应', [
            'client_id' => $context->getClientId(),
            'response_data_length' => strlen($responseData),
        ]);

        $packets = Parser::parsePackets($responseData);

        $this->connectionLogger->debug('MySQL响应解析完成', [
            'client_id' => $context->getClientId(),
            'packet_count' => count($packets),
        ]);

        // 检查MySQL返回的响应是否是错误包
        if (!empty($packets) && Response::isErrorPacket($packets[0])) {
            $errorData = Response::parseErrorPacket($packets[0]);
            $this->connectionLogger->error('MySQL查询错误', [
                'client_id' => $context->getClientId(),
                'error_code' => $errorData['error_code'],
                'sql_state' => $errorData['sql_state'],
                'error_message' => $errorData['error_message'],
            ]);
        }

        if ($responseData !== '') {
            // 直接转发MySQL响应（保持原始sequence_id）
            $server->send((int) $context->getClientId(), $responseData);

            $this->connectionLogger->debug('已转发MySQL响应', [
                'client_id' => $context->getClientId(),
                'response_length' => strlen($responseData),
                'packet_count' => count($packets),
            ]);
        } else {
            $this->connectionLogger->warning('sql代理: MySQL响应为空', [
                'client_id' => $context->getClientId(),
            ]);
        }

        return $packets;
    }

    private function readAllPackets(Socket $socket): string
    {
        $buffer = '';
        $timeout = 2.0; // 超时时间

        $this->connectionLogger->debug('开始读取第一个数据包头', [
            'timeout' => $timeout,
        ]);

        // 读取第一个包
        $header = $socket->recvAll(4, $timeout);
        if ($header === false || strlen($header) !== 4) {
            $this->connectionLogger->error('sql代理: 读取包头失败或超时', [
                'expected_length' => 4,
                'actual_length' => $header === false ? 'false' : strlen($header),
                'timeout' => $timeout,
            ]);
            return $buffer;
        }

        $this->connectionLogger->debug('第一个包头读取成功', [
            'header_hex' => bin2hex($header),
        ]);

        $length = unpack('V', substr($header, 0, 3) . "\x00")[1];
        $this->connectionLogger->debug('解析包长度', [
            'payload_length' => $length,
        ]);

        $payload = $socket->recvAll($length, $timeout);
        if ($payload === false || strlen($payload) !== $length) {
            $this->connectionLogger->error('sql代理: 读取Payload失败或超时', [
                'expected_length' => $length,
                'actual_length' => $payload === false ? 'false' : strlen($payload),
                'timeout' => $timeout,
            ]);
        }

        $buffer .= $header . $payload;
        $numPackets = 1;

        // 检查第一个包的类型
        $firstPacket = Packet::fromString($header . $payload);
        $firstByte = ord($payload[0] ?? 0);
        $isHandshakePacket = ($firstByte === 10); // 握手包的protocol_version是10
        $isOkPacket = Response::isOkPacket($firstPacket); // OK包的第一个字节是0x00
        $isErrorPacket = Response::isErrorPacket($firstPacket); // ERR包的第一个字节是0xff

        $this->connectionLogger->debug('分析第一个包类型', [
            'first_byte' => $firstByte,
            'is_handshake_packet' => $isHandshakePacket,
            'is_ok_packet' => $isOkPacket,
            'is_error_packet' => $isErrorPacket,
        ]);

        // 如果是握手包、OK包或ERR包，只返回这一个包
        if ($isHandshakePacket || $isOkPacket || $isErrorPacket) {
            $packetType = $isHandshakePacket ? '握手包' : ($isOkPacket ? 'OK包' : 'ERR包');
            $this->connectionLogger->debug('检测到' . $packetType . '，停止读取', [
                'total_packets' => $numPackets,
                'total_bytes' => strlen($buffer),
            ]);
            return $buffer;
        }

        // 否则，继续读取后续数据包（ResultSet等）
        $this->connectionLogger->debug('开始读取后续数据包', [
            'current_packet_count' => $numPackets,
        ]);

        $maxRetries = 3; // 最多重试3次
        $retryCount = 0;

        while ($retryCount < $maxRetries) {
            $nextHeader = $socket->recv(4, $timeout);
            if (strlen($nextHeader) !== 4) {
                $this->connectionLogger->debug('后续包头读取完成，无更多数据', [
                    'header_length' => strlen($nextHeader),
                    'retry_count' => $retryCount,
                ]);
                break;
            }

            $nextLength = unpack('V', substr($nextHeader, 0, 3) . "\x00")[1];
            $nextPayload = $socket->recvAll($nextLength, $timeout);

            if ($nextPayload === false || strlen($nextPayload) !== $nextLength) {
                $this->connectionLogger->error('sql代理: 读取后续Payload失败或超时', [
                    'expected_length' => $nextLength,
                    'actual_length' => $nextPayload === false ? 'false' : strlen($nextPayload),
                    'timeout' => $timeout,
                    'retry_count' => $retryCount,
                ]);
                $retryCount++;
                continue;
            }

            $buffer .= $nextHeader . $nextPayload;

            // EOF包表示结果集结束
            $nextPacket = Packet::fromString($nextHeader . $nextPayload);
            if (Response::isEofPacket($nextPacket)) {
                $this->connectionLogger->debug('检测到EOF包，结果集结束', [
                    'packet_count' => $numPackets + 1,
                ]);
                break;
            }

            $numPackets++;
            $retryCount = 0; // 成功读取包，重置重试计数

            if ($numPackets > 10000) { // 防止无限循环
                $this->connectionLogger->warning('sql代理: 数据包数量超过限制，停止读取', [
                    'max_packets' => 10000,
                    'current_count' => $numPackets,
                ]);
                break;
            }
        }

        $this->connectionLogger->debug('所有数据包读取完成', [
            'total_packets' => $numPackets,
            'total_bytes' => strlen($buffer),
        ]);

        return $buffer;
    }

    public function onClose(\Swoole\Server $server, int $fd, int $reactorId): void
    {
        $clientId = (string) $fd;

        $this->connectionLogger->info('客户端连接关闭', [
            'client_id' => $clientId,
            'reactor_id' => $reactorId,
        ]);

        if (isset($this->connections[$clientId])) {
            $context = $this->connections[$clientId];

            $this->connectionLogger->info('客户端连接关闭详情', [
                'client_id' => $clientId,
                'client_ip' => $context->getClientIp(),
                'client_port' => $context->getClientPort(),
                'in_transaction' => $context->isInTransaction(),
                'mysql_socket_exists' => $context->getMysqlSocket() !== null,
                'target_host' => $context->getTargetHost(),
                'target_port' => $context->getTargetPort(),
            ]);

            // 关闭目标MySQL连接
            if ($context->getMysqlSocket()) {
                $this->connectionLogger->debug('关闭目标MySQL连接', [
                    'client_id' => $clientId,
                ]);

                $context->getMysqlSocket()->close();

                $this->connectionLogger->debug('目标MySQL连接已关闭', [
                    'client_id' => $clientId,
                ]);
            }

            unset($this->connections[$clientId]);

            $this->connectionLogger->info('已移除连接上下文', [
                'client_id' => $clientId,
            ]);
        } else {
            $this->connectionLogger->warning('sql代理: 未找到连接上下文', [
                'client_id' => $clientId,
            ]);
        }
    }

    /**
     * 将二进制数据转换为可读的ASCII字符串（用于日志）
     */
    private function toAscii(string $data): string
    {
        $result = '';
        $length = min(strlen($data), 256); // 限制长度

        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];
            $ord = ord($char);

            if ($ord >= 32 && $ord <= 126) {
                // 可打印字符
                $result .= $char;
            } else {
                // 控制字符，用点号代替
                $result .= '.';
            }
        }

        return $result;
    }
}
