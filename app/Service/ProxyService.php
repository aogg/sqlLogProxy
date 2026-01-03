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
use App\Service\TargetConnector;
use App\Service\ProxyTlsHandler;
use App\Proxy\Service\MySQLProxyService;
use App\Proxy\Protocol\MySQLHandshake as NewMySQLHandshake;
use App\Proxy\Auth\ProxyAuthenticator;
use App\Proxy\Executor\BackendExecutor;
use function Hyperf\Config\config;

/**
 * 代理服务
 *
 * @deprecated 请使用 MySQLProxyService
 */
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
    private LoggerInterface $mysqlSendLogger;
    private ?ProxyTlsHandler $tlsHandler = null;

    public function __construct(ContainerInterface $container, LoggerFactory $loggerFactory)
    {
        $this->container = $container;
        $this->logger = $loggerFactory->get('default');
        $this->sqlLogger = $loggerFactory->get('sql');
        $this->connectionLogger = $loggerFactory->get('connection');
        $this->mysqlSendLogger = $loggerFactory->get('mysql_send');
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
        $this->mysqlSendLogger->info('MySQL Send Logger初始化成功');
        $this->mysqlSendLogger->debug('测试mysql_send日志器', ['test' => 'mysql_send_logger_working']);
    }

    /**
     * Initialize the proxy service
     */
    public function initialize(): void
    {
        try {
            $config = config('proxy', []);
            $tlsConfig = $config['tls'] ?? [];

            // Initialize TLS handler
            if (!empty($tlsConfig['server_cert']) && !empty($tlsConfig['server_key'])) {
                $this->tlsHandler = new ProxyTlsHandler(
                    $this->connectionLogger,
                    $tlsConfig['server_cert'],
                    $tlsConfig['server_key'],
                    $tlsConfig['ca_cert'] ?? null,
                    $tlsConfig['require_client_cert'] ?? false
                );

                $this->logger->info('TLS handler initialized', [
                    'server_cert' => $tlsConfig['server_cert'],
                    'server_key' => $tlsConfig['server_key'],
                    'require_client_cert' => $tlsConfig['require_client_cert'] ?? false,
                ]);
            } else {
                $this->logger->warning('TLS certificates not configured, TLS termination will not be available');
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize proxy service', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
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
        $clientId = $fd;

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

        // 检测是否是TLS握手数据
        if ($this->isTlsHandshake($data)) {
            $this->connectionLogger->info('检测到TLS握手数据，尝试启用SSL支持', [
                'client_id' => $clientId,
                'data_length' => strlen($data),
            ]);

            // 尝试在现有连接上启用SSL
            try {
                $socket = $server->getClientInfo((int) $clientId);
                if ($socket && isset($socket['socket_fd'])) {
                    // 获取原始socket并启用SSL
                    $swooleSocket = new \Swoole\Coroutine\Socket($socket['socket_fd']);
                    $sslResult = $swooleSocket->enableSSL([
                        'ssl_cert_file' => BASE_PATH . '/runtime/certs/server.crt',
                        'ssl_key_file' => BASE_PATH . '/runtime/certs/server.key',
                        'ssl_verify_peer' => false,
                        'ssl_allow_self_signed' => true,
                    ]);

                    if ($sslResult) {
                        $this->connectionLogger->info('SSL启用成功，继续处理连接', ['client_id' => $clientId]);
                        // SSL启用成功，继续正常处理
                        return;
                    } else {
                        $this->connectionLogger->warning('SSL启用失败', ['client_id' => $clientId]);
                    }
                }
            } catch (\Exception $e) {
                $this->connectionLogger->error('SSL启用异常', [
                    'client_id' => $clientId,
                    'error' => $e->getMessage()
                ]);
            }

            // SSL启用失败，发送错误并关闭
            $errorMessage = "SSL handshake failed. Please check server SSL configuration or disable SSL on client side.";
            $errorPacket = $this->createErrorPacket($errorMessage);

            $server->send((int) $clientId, $errorPacket);

            \Swoole\Coroutine::create(function () use ($server, $clientId) {
                \Swoole\Coroutine::sleep(0.1);
                $server->close((int) $clientId);
            });

            return;
        }

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

            $targetHost = $this->getBackendConfig()['host'];
            $targetPort = $this->getBackendConfig()['port'];
            $dsn = "host={$targetHost};port={$targetPort};dbname={$authResponse['database']}";

            $this->connectionLogger->debug('准备建立目标MySQL连接', [
                'client_id' => $context->getClientId(),
                'dsn' => $dsn,
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'database' => $authResponse['database'],
            ]);

            // 建立到目标MySQL的连接
            $mysqlSocket = $this->connectToTarget($this->getBackendConfig()['host'], $this->getBackendConfig()['port']);

            if (!$mysqlSocket) {
                $this->connectionLogger->error('连接到目标MySQL失败', [
                    'client_id' => $context->getClientId(),
                    'target_host' => $targetHost,
                    'target_port' => $targetPort,
                ]);
                throw new \RuntimeException('Failed to connect to target MySQL');
            }

            // 保存连接到上下文
            $context->setMysqlSocket($mysqlSocket);
            $context->setDsnParams([
                'host' => $authResponse['username'],
                'port' => $targetPort,
                'database' => $authResponse['database'],
            ]);

            $this->connectionLogger->info('MySQL连接建立成功', [
                'client_id' => $context->getClientId(),
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'database' => $authResponse['database'],
            ]);

            // 转发认证信息到目标MySQL
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

        // 处理目标MySQL连接
        if ($context->getMysqlSocket()) {
            $dsnParams = $context->getDsnParams();

            // 检查是否是从连接池借用的连接
            if (isset($dsnParams['pool_connection']) && $dsnParams['pool_connection']) {
                // 将连接返回给池
                $this->returnConnectionToPool($context->getMysqlSocket());
                $this->connectionLogger->debug('已将MySQL连接返回给连接池', [
                    'client_id' => $clientId,
                    'pool_stats' => $this->getPoolStats(),
                ]);
            } else {
                // 直接关闭连接（旧逻辑，兼容性）
                $context->getMysqlSocket()->close();
                $this->connectionLogger->debug('已关闭目标MySQL连接', [
                    'client_id' => $clientId,
                ]);
            }
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

        // 使用锁来确保只有一个协程可以访问MySQL socket
        $lock = $context->getSocketLock();
        $lock->lock();

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

                $this->mysqlSendLogger->info('发送认证数据到MySQL', [
                    'client_id' => $context->getClientId(),
                    'data_length' => strlen($authData),
                    'data_hex' => bin2hex($authData),
                    'data_ascii' => $this->toAscii($authData),
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
        } finally {
            $lock->unlock();
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

        // 使用锁来确保只有一个协程可以访问MySQL socket
        $lock = $context->getSocketLock();
        $lock->lock();

        try {
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

            $this->mysqlSendLogger->info('发送数据包到MySQL', [
                'client_id' => $context->getClientId(),
                'command' => $packet->getCommand(),
                'sequence_id' => $packet->getSequenceId(),
                'data_length' => strlen($packetData),
                'data_hex' => bin2hex($packetData),
                'data_ascii' => $this->toAscii($packetData),
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
        } finally {
            $lock->unlock();
        }
    }

    private function forwardToTargetAndGetResponse(\Swoole\Server $server, ConnectionContext $context, Packet $packet): array
    {
        $maxRetries = 2; // 最大重试次数
        $retryCount = 0;

        while ($retryCount <= $maxRetries) {
            try {
                $socket = $context->getMysqlSocket();

                if (!$socket) {
                    $this->connectionLogger->debug('MySQL连接不存在，尝试建立连接', [
                        'client_id' => $context->getClientId(),
                        'retry_count' => $retryCount,
                    ]);
                    $this->connectToTargetAndForward($server, $context, $packet);
                    return [];
                }

                // 使用锁来确保只有一个协程可以访问MySQL socket
                $lock = $context->getSocketLock();
                $lock->lock();

                try {
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

                    $this->mysqlSendLogger->info('发送SQL查询到MySQL', [
                        'client_id' => $context->getClientId(),
                        'command' => $packet->getCommand(),
                        'sequence_id' => $packet->getSequenceId(),
                        'data_length' => strlen($packetData),
                        'data_hex' => bin2hex($packetData),
                        'data_ascii' => $this->toAscii($packetData),
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
                } finally {
                    $lock->unlock();
                }
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'Lost connection to MySQL server') && $retryCount < $maxRetries) {
                    $this->connectionLogger->warning('MySQL连接丢失，尝试重连', [
                        'client_id' => $context->getClientId(),
                        'retry_count' => $retryCount + 1,
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                    ]);

                    // 关闭当前连接
                    if ($context->getMysqlSocket()) {
                        $context->getMysqlSocket()->close();
                        $context->setMysqlSocket(null);
                    }

                    $retryCount++;
                    \Swoole\Coroutine::sleep(0.1 * $retryCount); // 递增延迟重试
                    continue;
                }

                throw $e;
            }
        }

        // 如果所有重试都失败，抛出异常
        throw new \RuntimeException('Failed to execute query after ' . $maxRetries . ' retries');
    }

    private function readAllPackets(Socket $socket): string
    {
        $buffer = '';
        $timeout = 30.0; // 增加超时时间到30秒，避免查询超时

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
                'error_message' => $header === false ? 'Connection lost or timeout' : 'Incomplete header',
            ]);

            // 如果是连接丢失，抛出异常让上层处理
            if ($header === false) {
                throw new \RuntimeException('Lost connection to MySQL server during query');
            }

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

    /**
     * 读取MySQL的TLS响应数据
     */
    private function readTlsResponse(Socket $socket): string
    {
        $buffer = '';
        $timeout = 0.1; // 减少超时时间，快速检测是否有数据

        $this->connectionLogger->debug('开始读取TLS响应数据', [
            'timeout' => $timeout,
        ]);

        try {
            // TLS握手可能包含多个记录，我们尝试读取第一个记录
            // 如果没有立即可用的数据，就不阻塞等待
            $tlsHeader = $socket->recvAll(5, $timeout); // 读取TLS记录头

            if ($tlsHeader === false || strlen($tlsHeader) !== 5) {
                $this->connectionLogger->debug('TLS响应读取完成或失败，没有立即可用的数据', [
                    'expected_length' => 5,
                    'actual_length' => $tlsHeader === false ? 'false' : strlen($tlsHeader),
                ]);
                return $buffer;
            }

            // 解析TLS记录长度 (最后2字节是大端序的长度)
            $length = unpack('n', substr($tlsHeader, 3, 2))[1];

            $this->connectionLogger->debug('TLS记录头解析成功', [
                'header_hex' => bin2hex($tlsHeader),
                'content_type' => ord($tlsHeader[0]),
                'version' => bin2hex(substr($tlsHeader, 1, 2)),
                'payload_length' => $length,
            ]);

            // 读取TLS记录的payload
            if ($length > 0) {
                $payload = $socket->recvAll($length, 1.0); // 给payload更多的读取时间
                if ($payload === false || strlen($payload) !== $length) {
                    $this->connectionLogger->error('读取TLS payload失败', [
                        'expected_length' => $length,
                        'actual_length' => $payload === false ? 'false' : strlen($payload),
                    ]);
                    return $buffer;
                }

                $buffer = $tlsHeader . $payload;

                $this->connectionLogger->debug('TLS响应数据读取完成', [
                    'total_length' => strlen($buffer),
                    'payload_hex' => bin2hex(substr($payload, 0, 64)),
                ]);
            } else {
                $buffer = $tlsHeader;
                $this->connectionLogger->debug('TLS记录payload长度为0', [
                    'total_length' => strlen($buffer),
                ]);
            }

        } catch (\Exception $e) {
            $this->connectionLogger->error('读取TLS响应异常', [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ]);
        }

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
     * 检测是否是TLS握手数据
     */
    private function isTlsHandshake(string $data): bool
    {
        if (strlen($data) < 5) {
            return false;
        }

        // TLS记录格式: ContentType(1) + Version(2) + Length(2) + ...
        $contentType = ord($data[0]);
        $majorVersion = ord($data[1]);
        $minorVersion = ord($data[2]);

        // TLS握手记录: ContentType = 22 (0x16)
        // 版本应该是TLS 1.0-1.3: 0x0300, 0x0301, 0x0302, 0x0303
        return $contentType === 22 && $majorVersion === 3 && $minorVersion >= 0 && $minorVersion <= 3;
    }

    /**
     * 转发TLS数据到MySQL
     */
    private function forwardTlsData(\Swoole\Server $server, ConnectionContext $context, string $data): void
    {
        $socket = $context->getMysqlSocket();

        if (!$socket) {
            $this->connectionLogger->error('MySQL连接不存在，无法转发TLS数据', [
                'client_id' => $context->getClientId(),
            ]);
            return;
        }

        // 使用锁来确保只有一个协程可以访问MySQL socket
        $lock = $context->getSocketLock();
        $lock->lock();

        try {
            // 发送TLS数据到MySQL
            $sendResult = $socket->sendAll($data);

            $this->mysqlSendLogger->info('发送TLS握手数据到MySQL', [
                'client_id' => $context->getClientId(),
                'data_length' => strlen($data),
                'data_hex' => bin2hex(substr($data, 0, 64)),
                'sent_bytes' => $sendResult,
            ]);

            // TLS握手后，MySQL可能会立即发送响应，我们尝试读取
            $this->connectionLogger->debug('TLS握手数据已转发，尝试读取MySQL响应', [
                'client_id' => $context->getClientId(),
            ]);

            // 短暂延迟后尝试读取MySQL的TLS响应
            \Swoole\Coroutine::create(function () use ($server, $context, $socket, $lock) {
                try {
                    // 等待一小段时间让TLS握手完成
                    \Swoole\Coroutine::sleep(0.01);

                    // 重新获取锁来读取响应
                    $lock->lock();
                    try {
                        $response = $this->readTlsResponse($socket);

                        if ($response !== '') {
                            $this->connectionLogger->debug('读取到MySQL TLS响应', [
                                'client_id' => $context->getClientId(),
                                'response_length' => strlen($response),
                            ]);

                            // 转发TLS响应给客户端
                            $server->send((int) $context->getClientId(), $response);
                        }
                    } finally {
                        $lock->unlock();
                    }
                } catch (\Exception $e) {
                    $this->connectionLogger->error('异步读取MySQL TLS响应失败', [
                        'client_id' => $context->getClientId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        } catch (\Exception $e) {
            $this->connectionLogger->error('转发TLS数据失败', [
                'client_id' => $context->getClientId(),
                'error' => $e->getMessage(),
            ]);
        } finally {
            $lock->unlock();
        }
    }

    /**
     * 创建TLS错误响应
     */
    private function createTlsErrorPacket(string $message): string
    {
        // 发送TLS Alert消息，告诉客户端握手失败
        // TLS Alert格式: ContentType(1) + Version(2) + Length(2) + Level(1) + Description(1)
        $alert = chr(21); // Alert (21)
        $alert .= chr(3) . chr(3); // TLS 1.2
        $alert .= chr(0) . chr(2); // Length = 2
        $alert .= chr(2); // Fatal (2)
        $alert .= chr(40); // Handshake failure (40)

        return $alert;
    }

    /**
     * 创建MySQL错误包
     */
    private function createErrorPacket(string $message): string
    {
        // MySQL错误包格式: packet_length(3) + sequence_id(1) + error_code(2) + sql_state_marker(1) + sql_state(5) + error_message
        $errorCode = 2000; // ER_UNKNOWN_ERROR
        $sqlState = 'HY000';
        $errorMessage = substr($message, 0, 255); // 限制消息长度

        $payload = chr(0xff); // 错误包标记
        $payload .= pack('v', $errorCode); // 错误代码
        $payload .= '#'; // SQL状态标记
        $payload .= $sqlState; // SQL状态
        $payload .= $errorMessage; // 错误消息

        // 添加包头: length(3 bytes) + sequence_id(1 byte)
        $length = strlen($payload);
        $header = pack('V', $length) & "\xff\xff\xff"; // 3字节长度
        $header .= chr(1); // sequence_id = 1

        return $header . $payload;
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

    /**
     * 获取后端MySQL配置
     */
    private function getBackendConfig(): array
    {
        $config = config('proxy.backend_mysql', []);
        return [
            'host' => $config['host'] ?? 'mysql57.common-all',
            'port' => $config['port'] ?? 3306,
            'username' => $config['username'] ?? 'root',
            'password' => $config['password'] ?? 'root',
            'database' => $config['database'] ?? '',
            'charset' => $config['charset'] ?? 'utf8mb4',
            'connect_timeout' => $config['connect_timeout'] ?? 15.0,
            'read_timeout' => $config['read_timeout'] ?? 120.0,
            'write_timeout' => $config['write_timeout'] ?? 120.0,
            'tls' => $config['tls'] ?? false,
        ];
    }

    /**
     * 连接到目标MySQL服务器
     */
    private function connectToTarget(string $host, int $port): ?Socket
    {
        try {
            $this->connectionLogger->info('开始连接到目标MySQL', [
                'host' => $host,
                'port' => $port,
            ]);

            $connector = new TargetConnector(
                $host,
                $port,
                5.0, // 连接超时
                false // 不使用TLS
            );

            $socket = $connector->connect();

            if ($socket) {
                $this->connectionLogger->info('成功连接到目标MySQL', [
                    'host' => $host,
                    'port' => $port,
                ]);
            } else {
                $this->connectionLogger->error('连接到目标MySQL失败', [
                    'host' => $host,
                    'port' => $port,
                ]);
            }

            return $socket;

        } catch (\Exception $e) {
            $this->connectionLogger->error('连接目标MySQL时发生异常', [
                'host' => $host,
                'port' => $port,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
