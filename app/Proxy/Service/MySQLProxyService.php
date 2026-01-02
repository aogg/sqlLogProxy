<?php

declare(strict_types=1);

namespace App\Proxy\Service;

use App\Protocol\MySql\Packet;
use App\Protocol\MySql\Parser;
use App\Protocol\MySql\Auth;
use App\Protocol\MySql\Prepare;
use App\Protocol\MySql\Execute;
use App\Protocol\ConnectionContext;
use App\Proxy\Protocol\MySQLHandshake;
use App\Proxy\Auth\ProxyAuthenticator;
use App\Proxy\Executor\BackendExecutor;
use App\Proxy\Client\ClientDetector;
use App\Proxy\Client\ProtocolAdapter;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Socket;
use function Hyperf\Config\config;

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
    private ClientDetector $clientDetector;
    private ProtocolAdapter $protocolAdapter;
    private array $connections = [];

    public function __construct(
        LoggerFactory $loggerFactory,
        MySQLHandshake $handshake,
        ProxyAuthenticator $authenticator,
        BackendExecutor $executor,
        ClientDetector $clientDetector,
        ProtocolAdapter $protocolAdapter
    ) {
        $this->logger = $loggerFactory->get('proxy_service');
        $this->handshake = $handshake;
        $this->authenticator = $authenticator;
        $this->executor = $executor;
        $this->clientDetector = $clientDetector;
        $this->protocolAdapter = $protocolAdapter;
    }

    /**
     * 处理客户端连接
     */
    public function handleConnect(ConnectionContext $context): ?Packet
    {
        $this->logger->info('客户端连接，开始发送握手包', [
            'client_id' => $context->getClientId(),
            'remote_ip' => $context->getClientIp(),
            'remote_port' => $context->getClientPort(),
        ]);

        // 生成 authPluginData
        // MySQL 5.7.44 使用 21 字节的 auth_plugin_data（8 + 13）
        $authPluginData = $this->handshake->generateAuthPluginData(21);
        $context->setAuthPluginData($authPluginData);

        // 立即发送握手包
        $handshakePacket = $this->handshake->createServerHandshake(
            (int) $context->getThreadId(),
            $authPluginData
        );

        $this->logger->info('已创建服务器握手包', [
            'client_id' => $context->getClientId(),
            'auth_plugin_data_hex' => bin2hex($authPluginData),
            'packet_length' => strlen($handshakePacket->toBytes()),
        ]);

        return $handshakePacket;
    }

    /**
     * 处理客户端数据包
     *
     * @param ConnectionContext $context 连接上下文
     * @param Packet $packet 数据包
     * @return Packet[]
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
        $response = $this->handshake->handleClientHandshakeResponse($packet, $context, $this->clientDetector);

        if ($response['type'] === 'ssl_request') {
            $this->logger->info('客户端请求 SSL 连接', [
                'client_id' => $context->getClientId(),
                'client_type' => $context->getClientType()->value,
            ]);

            // 标记客户端需要 SSL - 不返回SSL切换包，上层ProxyService将在socket上启用TLS
            $context->setSslRequested(true);

            // 返回空数组，表示SSL请求已处理，不需要发送MySQL协议包
            return [];
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
            $this->logger->info('开始处理客户端认证请求', [
                'client_id' => $context->getClientId(),
                'packet_sequence_id' => $packet->getSequenceId(),
                'packet_length' => strlen($packet->getPayload()),
            ]);

            // 解析认证信息并进行客户端检测
            $authData = $this->handshake->handleClientHandshakeResponse($packet, $context, $this->clientDetector);

            if ($authData['type'] !== 'auth_response') {
                $this->logger->warning('收到非认证响应数据包', [
                    'client_id' => $context->getClientId(),
                    'response_type' => $authData['type'],
                ]);
                throw new \RuntimeException('Invalid authentication data');
            }

            $username = $authData['username'];
            $authResponse = $authData['auth_response'];
            $database = $authData['database'] ?? '';
            $authPluginName = $authData['auth_plugin_name'] ?? '';
            $charset = $authData['charset'] ?? 0;
            $maxPacketSize = $authData['max_packet_size'] ?? 0;

            // 记录客户端发送的完整认证信息
            $this->logger->info('客户端认证信息详情', [
                'client_id' => $context->getClientId(),
                'username' => $username,
                'database' => $database,
                'auth_plugin_name' => $authPluginName,
                'charset' => $charset,
                'charset_name' => $this->getCharsetName($charset),
                'max_packet_size' => $maxPacketSize,
                'auth_response_length' => strlen($authResponse),
                'auth_response_hex' => bin2hex($authResponse),
                'server_auth_plugin_data_hex' => bin2hex($context->getAuthPluginData()),
                'has_database' => !empty($database),
                'client_capabilities' => sprintf('0x%08x', $authData['capabilities'] ?? 0),
            ]);

            // 当启用 CLIENT_PLUGIN_AUTH 时，第一个包的 auth_response 可能为空
            // 实际的认证响应会在第二个包（sequence_id=2）中发送
            // 检查是否是第一个包（auth_response_length=0 且已经解析出了用户名）
            if (strlen($authResponse) === 0 && !empty($username)) {
                // 检查该用户的密码是否为空
                $proxyAccounts = config('proxy.proxy_accounts', []);
                $isEmptyPassword = false;
                foreach ($proxyAccounts as $account) {
                    if (($account['username'] ?? '') === $username && empty($account['password'] ?? '')) {
                        $isEmptyPassword = true;
                        break;
                    }
                }

                if ($isEmptyPassword) {
                    $this->logger->info('收到空密码认证，直接认证成功', [
                        'client_id' => $context->getClientId(),
                        'username' => $username,
                        'sequence_id' => $packet->getSequenceId(),
                    ]);

                    // 标记认证成功
                    $context->setAuthenticated(true);
                    $context->setUsername($username);
                    if (!empty($database)) {
                        $context->setDatabase($database);
                    }

                    // 返回认证成功包
                    return $this->createAuthSuccessPackets();
                }

                $this->logger->info('收到第一个认证包，等待第二个包', [
                    'client_id' => $context->getClientId(),
                    'username' => $username,
                    'sequence_id' => $packet->getSequenceId(),
                ]);

                // 保存已解析的信息到上下文，等待第二个包
                $context->setUsername($username);
                if (!empty($database)) {
                    $context->setDatabase($database);
                }

                // 返回空数组，表示不需要发送响应包
                return [];
            }

            // 验证代理账号
            $this->logger->debug('开始验证代理账号', [
                'client_id' => $context->getClientId(),
                'username' => $username,
                'database' => $database,
                'auth_plugin_data_length' => strlen($context->getAuthPluginData()),
            ]);

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
                $this->logger->warning('代理认证失败  Access denied for user', [
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
                'parsedCommand' => $parsedCommand,
            ]);

            // 处理sql
            if (!empty($parsedCommand['sql'])) {
                $parsedCommand['sql'] = (new \App\Helpers\PHPSQLParserHelper($parsedCommand['sql']));
            }

            switch ($parsedCommand['type']) {
                case 'query':
                    return $this->handleQuery($context, $parsedCommand['sql']);

                case 'prepare':
                    return $this->handlePrepare($context, $parsedCommand['sql']);

                case 'execute':
                    return $this->handleExecute($context, $parsedCommand['data']);

                case 'use':
                    return $this->handleUse($context, $parsedCommand['database']);

                case 'ping':
                    return $this->handlePing($context);

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
    private function handleQuery(ConnectionContext $context, \App\Helpers\PHPSQLParserHelper $sql): array
    {
        // 从查询中检测客户端类型（如果还没检测到）
        $this->clientDetector->detectFromQuery($context, $sql->sql);

        $this->logger->info('handleQuery 执行 SQL 查询', [
            'client_id' => $context->getClientId(),
            'username' => $context->getUsername(),
            'client_type' => $context->getClientType()->value,
            'client_version' => $context->getClientVersion(),
            'sql' => $sql->sql,
        ]);

        // 使用后端执行器执行 SQL
        $packets = $this->executor->execute($sql, $context->getDatabase());

        // 调整包的sequence_id，从客户端命令包的sequence_id + 1开始
        $adjustedPackets = [];
        $startSequenceId = 1; // 响应包从sequence_id=1开始

        foreach ($packets as $packet) {
            $adjustedPackets[] = Packet::create($startSequenceId++, $packet->getPayload());
        }

        // 记录所有返回的数据包
        $this->logger->info('handleQuery 准备返回数据包给客户端', [
            'client_id' => $context->getClientId(),
            'packet_count' => count($adjustedPackets),
            'packets_info' => array_map(function($packet) {
                return [
                    'sequence_id' => $packet->getSequenceId(),
                    'payload_length' => strlen($packet->getPayload()),
                    'payload_hex' => bin2hex(substr($packet->getPayload(), 0, 32)) . (strlen($packet->getPayload()) > 32 ? '...' : ''),
                ];
            }, $adjustedPackets),
        ]);

        return $adjustedPackets;

        // 记录所有返回的数据包
        $this->logger->info('准备返回数据包给客户端', [
            'client_id' => $context->getClientId(),
            'packet_count' => count($adjustedPackets),
            'packets_info' => array_map(function($packet) {
                return [
                    'sequence_id' => $packet->getSequenceId(),
                    'payload_length' => strlen($packet->getPayload()),
                    'payload_hex' => bin2hex(substr($packet->getPayload(), 0, 32)) . (strlen($packet->getPayload()) > 32 ? '...' : ''),
                ];
            }, $adjustedPackets),
        ]);

        return $adjustedPackets;
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
     * 处理 ping 命令
     */
    private function handlePing(ConnectionContext $context): array
    {
        $this->logger->debug('处理 ping 命令', [
            'client_id' => $context->getClientId(),
            'username' => $context->getUsername(),
        ]);

        // 返回 OK 包表示连接正常
        return [Auth::createOkPacket()];
    }

    /**
     * 处理 use 命令（选择数据库）
     */
    private function handleUse(ConnectionContext $context, string $database): array
    {
        $this->logger->info('处理 USE 命令，选择数据库', [
            'client_id' => $context->getClientId(),
            'username' => $context->getUsername(),
            'old_database' => $context->getDatabase(),
            'new_database' => $database,
        ]);

        try {
            // 设置新的数据库
            $context->setDatabase($database);

            $this->logger->info('数据库切换成功', [
                'client_id' => $context->getClientId(),
                'username' => $context->getUsername(),
                'current_database' => $context->getDatabase(),
            ]);

            // 创建OK包，包含正确的状态标志
            $okPacket = Auth::createOkPacket(0, 0, 0x0002, 0); // SERVER_STATUS_AUTOCOMMIT
            $okPacket->toBytes = bytes_to_string(Auth::$OK);

            $this->logger->debug('USE命令响应包详情', [
                'client_id' => $context->getClientId(),
                'packet_sequence_id' => $okPacket->getSequenceId(),
                'packet_payload_length' => strlen($okPacket->getPayload()),
                'packet_payload_hex' => bin2hex($okPacket->getPayload()),
                'status_flags' => '0x0002 (SERVER_STATUS_AUTOCOMMIT)',
            ]);

            return [$okPacket];
        } catch (\Exception $e) {
            $this->logger->error('USE命令处理异常', [
                'client_id' => $context->getClientId(),
                'database' => $database,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorPackets('Failed to switch database: ' . $e->getMessage());
        }
    }

    /**
     * 处理预编译语句准备命令
     */
    private function handlePrepare(ConnectionContext $context, \App\Helpers\PHPSQLParserHelper $sql): array
    {
        $this->logger->info('收到预处理语句准备请求', [
            'client_id' => $context->getClientId(),
            'username' => $context->getUsername(),
            'sql' => $sql,
        ]);

        // 转发到后端执行
        $packets = $this->executor->executePrepare($sql->sql, $context->getDatabase());

        // 注册预处理语句
        if (!empty($packets)) {
            $prepareResp = Prepare::parsePrepareResponse($packets[0]);
            $stmtId = $prepareResp['statement_id'];
            $numParams = $prepareResp['num_params'];

            Parser::registerPreparedStatement($stmtId, $sql->sql);
            Parser::setPreparedStatementParamCount($stmtId, $numParams);

            $this->logger->debug('预处理语句注册成功', [
                'client_id' => $context->getClientId(),
                'statement_id' => $stmtId,
                'num_params' => $numParams,
                'sql' => $sql,
            ]);
        }

        return $packets;
    }

    /**
     * 处理预编译语句执行命令
     */
    private function handleExecute(ConnectionContext $context, array $data): array
    {
        $stmtId = $data['statement_id'];
        $sql = Parser::getPreparedStatement($stmtId);

        $this->logger->info('收到预处理语句执行请求', [
            'client_id' => $context->getClientId(),
            'username' => $context->getUsername(),
            'statement_id' => $stmtId,
            'sql' => $sql,
        ]);

        // 记录执行日志
        if ($sql) {
            $this->logger->info('预处理语句执行', [
                'client_id' => $context->getClientId(),
                'username' => $context->getUsername(),
                'client_type' => $context->getClientType()->value,
                'client_version' => $context->getClientVersion(),
                'sql' => "[EXECUTE] " . $sql,
                'statement_id' => $stmtId,
            ]);
        }

        // 转发到后端执行
        return $this->executor->executeExecute($data, $context->getDatabase());
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
     * 获取字符集名称
     */
    private function getCharsetName(int $charset): string
    {
        $charsetMap = [
            33 => 'utf8_general_ci',
            45 => 'utf8mb4_general_ci',
            83 => 'utf8_bin',
            192 => 'utf8mb4_0900_bin',
            255 => 'utf8mb4_bin',
        ];

        return $charsetMap[$charset] ?? "charset_{$charset}";
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
    public function onConnect(
\Swoole\Server $server, int $fd, int $reactorId): void
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
        // 使用 21 字节的 auth_plugin_data（MySQL 5.7.44 要求）
        $authPluginData = $this->handshake->generateAuthPluginData(21);
        $context->setAuthPluginData($authPluginData);

        $this->connections[$clientId] = $context;

        // 立即发送服务器握手包
        try {
            $handshakePacket = $this->handleConnect($context);
            if ($handshakePacket !== null && ($handshakePacket instanceof Packet)) {
                try {
                    $server->send($fd, $handshakePacket->toBytes());
                    $this->logger->info('握手包已发送', [
                        'client_id' => $clientId,
                        'packet_length' => strlen($handshakePacket->toBytes()),
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('发送握手包异常', [
                        'client_id' => $clientId,
                        'error' => $e->getMessage(),
                    ]);
                    $server->close($fd);
                }
            }
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

        // 标记已收到客户端首包（用于 onConnect 的超时逻辑判断）
        try {
            $context->setFirstPacketReceived(true);
        } catch (\Throwable $_) {
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

                // 检查是否需要启用SSL
                if ($context->isSslRequested()) {
                    $this->logger->info('检测到SSL请求，启用TLS握手', [
                        'client_id' => $clientId,
                    ]);

                    // 在当前socket上启用TLS
                    $this->enableTlsOnSocket($server, $fd, $context);
                    return; // TLS启用后，不再发送MySQL协议包
                }

                // 发送响应包
                $this->logger->info('开始发送响应包给客户端', [
                    'client_id' => $clientId,
                    'packet_count' => count($responsePackets),
                ]);
                foreach ($responsePackets as $index => $responsePacket) {
                    $packetBytes = $responsePacket->toBytes();
                    $this->logger->debug('发送数据包', [
                        'client_id' => $clientId,
                        'packet_index' => $index,
                        'packet_length' => strlen($packetBytes),
                        'packet_hex' => bin2hex($packetBytes),
                        'sequence_id' => $responsePacket->getSequenceId(),
                        'payload_length' => $responsePacket->getLength(),
                    ]);
                    $server->send($fd, $packetBytes);
                    // 添加短暂延迟，防止包发送太快导致客户端解析问题
                    usleep(1000); // 1 毫秒
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

    /**
     * 在指定socket上启用TLS
     */
    private function enableTlsOnSocket(\Swoole\Server $server, int $fd, ConnectionContext $context): void
    {
        $clientId = (string) $fd;

        try {
            // 获取客户端socket信息
            $clientInfo = $server->getClientInfo($fd);
            if (!$clientInfo || !isset($clientInfo['socket_fd'])) {
                throw new \RuntimeException('无法获取客户端socket信息');
            }

            // 创建TLS处理器
            $tlsConfig = config('proxy.tls', []);
            if (empty($tlsConfig['server_cert']) || empty($tlsConfig['server_key'])) {
                throw new \RuntimeException('TLS证书未配置');
            }

            $tlsHandler = new \App\Service\ProxyTlsHandler(
                $this->logger,
                $tlsConfig['server_cert'],
                $tlsConfig['server_key'],
                $tlsConfig['ca_cert'] ?? null,
                $tlsConfig['require_client_cert'] ?? false
            );

            // 创建Swoole socket并启用TLS（以现有 socket fd 创建协程 socket）
            $swooleSocket = new \Swoole\Coroutine\Socket((int) $clientInfo['socket_fd'], AF_INET, SOCK_STREAM);

            $this->logger->debug('开始TLS握手', [
                'client_id' => $clientId,
                'socket_fd' => $clientInfo['socket_fd'],
            ]);

            // 执行TLS握手
            $tlsSuccess = $tlsHandler->performTlsHandshake($swooleSocket);

            if ($tlsSuccess) {
                $this->logger->info('TLS握手成功，标记上下文为SSL已启用', [
                    'client_id' => $clientId,
                ]);

                // 标记TLS已启用
                $context->setTlsEnabled(true);

                // TLS握手完成后，客户端会继续发送加密的MySQL认证数据
                // 这里不需要发送任何响应，下次收到数据时会是加密的认证信息
            } else {
                $this->logger->warning('TLS握手失败，关闭连接', [
                    'client_id' => $clientId,
                ]);

                // 发送错误信息并关闭连接
                $errorMessage = "SSL handshake failed. Please check server SSL configuration or disable SSL on client side.";
                $errorPackets = $this->createErrorPackets($errorMessage);
                $server->send($fd, $errorPackets[0]->toBytes());

                // 延迟关闭连接
                \Swoole\Coroutine::create(function () use ($server, $fd) {
                    \Swoole\Coroutine::sleep(0.1);
                    $server->close($fd);
                });
            }

        } catch (\Exception $e) {
            $this->logger->error('启用TLS异常', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // 发送错误并关闭连接
            try {
                $errorMessage = "SSL handshake failed: " . $e->getMessage();
                $errorPackets = $this->createErrorPackets($errorMessage);
                $server->send($fd, $errorPackets[0]->toBytes());
            } catch (\Exception $sendError) {
                $this->logger->error('发送TLS错误响应失败', [
                    'client_id' => $clientId,
                    'error' => $sendError->getMessage(),
                ]);
            }

            // 延迟关闭连接
            \Swoole\Coroutine::create(function () use ($server, $fd) {
                \Swoole\Coroutine::sleep(0.1);
                $server->close($fd);
            });
        }
    }
}
