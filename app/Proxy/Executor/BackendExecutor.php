<?php

declare(strict_types=1);

namespace App\Proxy\Executor;

use App\Helpers\PHPSQLParserHelper;
use Hyperf\DbConnection\Db;
use App\Protocol\MySql\Packet;
use App\Protocol\MySql\Parser;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * 后端 MySQL 执行器
 * 使用 Hyperf 数据库连接池连接并执行 SQL
 */
class BackendExecutor
{
    private LoggerInterface $logger;
    private bool $hasRetried = false;
    private int $maxRetries = 3; // 最大重试次数
    private float $retryDelay = 0.1; // 重试延迟（秒）

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('backend');

        $this->logger->info('后端执行器初始化，使用 Hyperf 数据库连接池');
    }

    /**
     * 执行 SQL 语句
     *
     * @param PHPSQLParserHelper $sql 要执行的 SQL 语句
     * @param string|null $database 要使用的数据库名
     * @return array 执行结果，包含 MySQL 协议包
     */
    public function execute(PHPSQLParserHelper $sql, ?string $database = null): array
    {
        $startTime = microtime(true);
        $retryCount = 0;

        $this->logger->info('开始执行后端 SQL', [
            'sql' => $sql,
            'max_retries' => $this->maxRetries,
        ]);

        while ($retryCount <= $this->maxRetries) {
            try {
                // 使用 Hyperf 数据库连接执行 SQL
                /** @var \Hyperf\DbConnection\Connection $connection */
                $connection = DB::connection('backend_mysql');

                // 检查连接是否可用，如果不可用则重新连接
                if (!$this->isConnectionValid($connection)) {
                    $this->logger->warning('后端连接不可用，尝试重新连接', [
                        'sql' => $sql,
                        'retry_count' => $retryCount,
                    ]);

                    // 强制重新获取连接
                    // 在 Hyperf 中，通过重新获取连接来处理连接问题
                    $connection = DB::connection('backend_mysql');

                    // 再次检查连接
                    if (!$this->isConnectionValid($connection)) {
                        throw new \RuntimeException('无法建立有效的数据库连接');
                    }
                }

            // 如果指定了数据库，先切换到该数据库
            if ($database !== null && $database !== '') {
                $connection->statement("USE `{$database}`");
                $this->logger->debug('已切换到数据库', [
                    'database' => $database,
                ]);
            }

            // 对于 SELECT 查询，使用 select 方法
            // 支持前置注释（例如 MySQL Connector/J 会在前面带注释），所以使用正则去除注释后判断
            if ($sql->isSelect()) {
                $result = $connection->select($sql->sql);
                // 某些 PDO 驱动或数据库层返回对象（stdClass），但下游期望关联数组
                if (!empty($result)) {
                    $result = array_map(function ($row) {
                        return is_array($row) ? $row : (array) $row;
                    }, $result);
                }

                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

                // 记录返回结果摘要（最多前3行数据，避免日志过大）
                $resultSummary = [];
                if (!empty($result)) {
                    $maxRows = min(3, count($result));
                    for ($i = 0; $i < $maxRows; $i++) {
                        $row = $result[$i];
                        // 只记录前5个字段，避免单行过长
                        $rowSummary = [];
                        $fieldCount = 0;
                        foreach ($row as $key => $value) {
                            if ($fieldCount >= 5) break;
                            $rowSummary[$key] = is_scalar($value) ? $value : gettype($value);
                            $fieldCount++;
                        }
                        $resultSummary[] = $rowSummary;
                    }
                }

                $this->logger->info('后端 SELECT 查询执行成功', [
                    'sql' => $sql,
                    'elapsed_ms' => $elapsedMs,
                    'result_count' => count($result),
                    'result_summary' => $resultSummary,
                ]);

                return $this->createResultSetPackets($result);
            } else {
                // 对于其他查询（如 INSERT, UPDATE, DELETE），使用 statement 方法
                $affectedRows = $connection->statement($sql->sql);
                // 某些驱动 statement() 返回 bool 而非受影响行数，统一转换为 int
                if (is_bool($affectedRows)) {
                    $affectedRows = $affectedRows ? 1 : 0;
                } else {
                    $affectedRows = (int) $affectedRows;
                }
                // 尝试通过可用方法获取最后插入 ID
                $lastInsertId = 0;
                if (method_exists($connection, 'getPdo')) {
                    $pdo = $connection->getPdo();
                    if ($pdo instanceof \PDO) {
                        $lastInsertId = (int) $pdo->lastInsertId();
                    }
                } elseif (method_exists($connection, 'lastInsertId')) {
                    $lastInsertId = (int) $connection->lastInsertId();
                }

                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);


                $this->logger->info('后端statement执行成功', [
                    'sql' => $sql,
                    'elapsed_ms' => $elapsedMs,
                    'affected_rows' => $affectedRows,
                    'last_insert_id' => $lastInsertId,
                ]);

                return $this->createOkPacket($affectedRows, $lastInsertId);
            }

            } catch (\Throwable $e) {
                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);
                $errorMessage = $e->getMessage();

                // 检查是否是连接相关错误且未达到最大重试次数
                if ($this->isConnectionError($errorMessage) && $retryCount < $this->maxRetries) {
                    $retryCount++;
                    $this->logger->warning('检测到连接错误，准备重试', [
                        'sql' => $sql,
                        'error' => $errorMessage,
                        'elapsed_ms' => $elapsedMs,
                        'retry_count' => $retryCount,
                        'max_retries' => $this->maxRetries,
                    ]);

                    // 等待重试延迟
                    if ($this->retryDelay > 0) {
                        \Swoole\Coroutine\System::sleep($this->retryDelay * $retryCount); // 递增延迟
                    }
                    continue; // 继续下一次重试
                }

                // 达到最大重试次数或非连接错误，抛出异常
                $this->logger->error('后端执行器异常', [
                    'sql' => $sql,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'elapsed_ms' => $elapsedMs,
                    'retry_count' => $retryCount,
                    'max_retries' => $this->maxRetries,
                ]);

                return $this->createErrorPackets(2000, 'Backend execution failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * 检查数据库连接是否有效
     */
    private function isConnectionValid($connection): bool
    {
        try {
            // 使用更可靠的连接检查方法
            // 1. 检查连接对象是否有效
            if (!$connection) {
                $this->logger->warning('连接对象无效');
                return false;
            }

            // 2. 对于 Hyperf 连接，直接尝试执行查询来验证连接
            // 使用 CONNECTION_ID() 来确保查询需要服务器响应，而不是从缓存返回
            $result = $connection->select('SELECT CONNECTION_ID() as conn_id, NOW() as `current_time`');
            if (empty($result)) {
                $this->logger->warning('连接检查查询失败 - 无返回结果');
                return false;
            }

            // 确保结果是数组格式
            $firstRow = is_array($result) ? $result[0] : (array) $result[0];
            // 如果仍然是对象，转换为数组
            if (is_object($firstRow)) {
                $firstRow = (array) $firstRow;
            }
            if (!isset($firstRow['conn_id'])) {
                $this->logger->warning('连接检查查询失败 - 缺少 conn_id 字段');
                return false;
            }

            $this->logger->debug('连接检查成功', [
                'connection_id' => $firstRow['conn_id'],
                'current_time' => $firstRow['current_time'] ?? null,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('连接检查失败', [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * 创建 OK 包
     */
    private function createOkPacket(int $affectedRows = 0, int $insertId = 0): array
    {
        // MySQL OK 包格式
        $payload = chr(0x00); // OK packet header
        $payload .= $this->encodeLength($affectedRows); // affected_rows
        $payload .= $this->encodeLength($insertId); // last_insert_id
        $payload .= pack('v', 0); // status_flags
        $payload .= pack('v', 0); // warnings

        return [Packet::create(0, $payload)];
    }

    /**
     * 创建结果集包
     */
    private function createResultSetPackets(array $result): array
    {
        $packets = [];

        if (empty($result)) {
            // 空结果集
            return $this->createEmptyResultSet();
        }

        // 获取列信息
        $columns = array_keys($result[0]);

        // Column Count Packet
        $packets[] = Packet::create(0, $this->encodeLength(count($columns)));

        // Column Definition Packets
        $sequenceId = 1;
        foreach ($columns as $column) {
            // 确保列名是字符串类型
            $columnName = is_string($column) ? $column : 'expr';
            $packets[] = Packet::create($sequenceId++, $this->createColumnDefinition($columnName));
        }

        // EOF Packet after columns
        $packets[] = Packet::create($sequenceId++, $this->createEofPacket()); // EOF

        // Row Data Packets
        foreach ($result as $row) {
            $rowData = '';
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $rowData .= $this->encodeLengthString((string) $value);
            }
            $packets[] = Packet::create($sequenceId++, $rowData);
        }

        // EOF Packet after rows
        $packets[] = Packet::create($sequenceId++, $this->createEofPacket()); // EOF

        return $packets;
    }

    /**
     * 创建EOF包
     */
    private function createEofPacket(): string
    {
        // MySQL 5.7 EOF包格式：只包含EOF标记
        // 在某些客户端实现中，EOF包只需要0xfe一个字节
        return chr(0xfe);
    }

    /**
     * 创建空结果集
     */
    private function createEmptyResultSet(): array
    {
        // Column count: 0
        $packets = [Packet::create(0, $this->encodeLength(0))];

        // EOF
        $packets[] = Packet::create(1, $this->createEofPacket());

        return $packets;
    }

    /**
     * 创建列定义
     */
    private function createColumnDefinition(string $columnName): string
    {
        $payload = '';
        $payload .= $this->encodeLengthString('def'); // catalog
        $payload .= $this->encodeLengthString(''); // schema
        $payload .= $this->encodeLengthString(''); // table
        $payload .= $this->encodeLengthString(''); // org_table
        $payload .= $this->encodeLengthString($columnName); // name
        $payload .= $this->encodeLengthString($columnName); // org_name
        $payload .= chr(0x0c); // length of fixed fields (always 0x0c)
        $payload .= pack('v', 33); // charset (utf8_general_ci = 33)
        $payload .= pack('V', 255); // max column length
        $payload .= chr(0xfd); // type (VAR_STRING = 0xfd)
        $payload .= pack('v', 0); // flags
        $payload .= chr(0); // decimals
        $payload .= pack('v', 0); // filler (2 bytes, always 0x00 0x00)

        return $payload;
    }

    /**
     * 创建错误包
     */
    private function createErrorPackets(int $errno, string $errorMessage): array
    {
        $payload = chr(0xff); // Error packet header
        $payload .= pack('v', $errno); // error code
        $payload .= '#'; // sql_state_marker
        $payload .= 'HY000'; // sql_state
        $payload .= $errorMessage; // error message

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
     * 编码长度字符串
     */
    private function encodeLengthString(string $str): string
    {
        return $this->encodeLength(strlen($str)) . $str;
    }

    /**
     * 关闭执行器
     */
    public function close(): void
    {
        // 使用 Hyperf 连接池时不需要手动关闭
        $this->logger->debug('后端执行器已关闭');
    }

    /**
     * 获取连接池状态
     */
    public function getPoolStats(): array
    {
        // 使用 Hyperf 的连接池统计
        try {
            /** @var \Hyperf\DbConnection\Connection $connection */
            $connection = DB::connection('backend_mysql');
            // 使用动态检测以兼容不同 DB 实现
            if (method_exists($connection, 'getPool')) {
                $pool = $connection->getPool();
                $stats = [
                    'pool_type' => 'hyperf',
                    'pool_name' => 'backend_mysql',
                    'connections_count' => $pool->getConnectionsCount(),
                    'idle_connections_count' => $pool->getIdleConnectionsCount(),
                ];

                // 添加连接健康检查
                $healthyConnections = 0;
                $totalChecked = min($stats['connections_count'] ?? 0, 5); // 检查最多5个连接

                for ($i = 0; $i < $totalChecked; $i++) {
                    try {
                        // 尝试获取一个连接并检查其有效性
                        $testConnection = DB::connection('backend_mysql');
                        if ($this->isConnectionValid($testConnection)) {
                            $healthyConnections++;
                        }
                    } catch (\Throwable $e) {
                        // 忽略检查过程中的异常
                    }
                }

                $stats['healthy_connections'] = $healthyConnections;
                $stats['health_check_sample_size'] = $totalChecked;
                $stats['health_percentage'] = $totalChecked > 0 ? round(($healthyConnections / $totalChecked) * 100, 2) : 0;

                return $stats;
            }

            // 如果没有 pool 支持，则返回基础信息
            return [
                'pool_type' => 'hyperf',
                'pool_name' => 'backend_mysql',
                'connections_count' => null,
                'idle_connections_count' => null,
                'healthy_connections' => null,
                'health_check_sample_size' => 0,
                'health_percentage' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'Failed to get pool stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 强制重建连接池
     * 用于在检测到大量连接问题时重建整个连接池
     */
    public function rebuildConnectionPool(): bool
    {
        try {
            $this->logger->info('开始重建连接池');

            // 清理现有连接池
            // 在 Hyperf 中，通过连接管理器清理连接池
            $container = \Hyperf\Context\ApplicationContext::getContainer();
            if ($container->has(\Hyperf\DbConnection\ConnectionResolverInterface::class)) {
                $resolver = $container->get(\Hyperf\DbConnection\ConnectionResolverInterface::class);
                if (method_exists($resolver, 'purge')) {
                    $resolver->purge('backend_mysql');
                }
            }

            // 等待一小段时间让清理完成
            \Swoole\Coroutine\System::sleep(0.1);

            // 获取新连接并验证
            $connection = DB::connection('backend_mysql');
            $isValid = $this->isConnectionValid($connection);

            $this->logger->info('连接池重建完成', [
                'connection_valid' => $isValid,
            ]);

            return $isValid;
        } catch (\Throwable $e) {
            $this->logger->error('连接池重建失败', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 执行预编译语句准备
     */
    public function executePrepare(string $sql, ?string $database = null): array
    {
        $startTime = microtime(true);

        $this->logger->info('开始执行预编译语句准备', [
            'sql' => $sql,
        ]);

        try {
            /** @var \Hyperf\DbConnection\Connection $connection */
            $connection = DB::connection('backend_mysql');

            // 如果指定了数据库，先切换到该数据库
            if ($database !== null && $database !== '') {
                $connection->statement("USE `{$database}`");
                $this->logger->debug('已切换到数据库', [
                    'database' => $database,
                ]);
            }

            if (method_exists($connection, 'getPdo')) {
                $pdo = $connection->getPdo();
                if ($pdo instanceof \PDO) {
                    $stmt = $pdo->prepare($sql);

                    // 获取statement_id - 这是一个简化的实现
                    // 在真实的MySQL协议中，statement_id由服务器分配
                    // 这里我们使用一个模拟的ID
                    $stmtId = crc32($sql) & 0x7FFFFFFF; // 确保是正数

                    $this->logger->info('预编译语句准备成功', [
                        'sql' => $sql,
                        'elapsed_ms' => (int) ((microtime(true) - $startTime) * 1000),
                        'statement_id' => $stmtId,
                    ]);

                    // 创建简化的PREPARE响应包
                    return $this->createPrepareResponsePacket($stmtId, $stmt);
                }
            }

            throw new \RuntimeException('无法获取PDO连接');

        } catch (\Throwable $e) {
            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->logger->error('预编译语句准备失败', [
                'sql' => $sql,
                'error' => $e->getMessage(),
                'elapsed_ms' => $elapsedMs,
            ]);

            return $this->createErrorPackets(2001, 'Prepare failed: ' . $e->getMessage());
        }
    }

    /**
     * 执行预编译语句执行
     */
    public function executeExecute(array $data, ?string $database = null): array
    {
        $stmtId = $data['statement_id'];
        $parameters = $data['parameters'] ?? [];
        $startTime = microtime(true);

        $this->logger->info('开始执行预编译语句', [
            'statement_id' => $stmtId,
            'parameter_count' => count($parameters),
        ]);

        try {
            /** @var \Hyperf\DbConnection\Connection $connection */
            $connection = DB::connection('backend_mysql');

            // 如果指定了数据库，先切换到该数据库
            if ($database !== null && $database !== '') {
                $connection->statement("USE `{$database}`");
                $this->logger->debug('已切换到数据库', [
                    'database' => $database,
                ]);
            }

            if (method_exists($connection, 'getPdo')) {
                $pdo = $connection->getPdo();
                if ($pdo instanceof \PDO) {
                    $sql = Parser::getPreparedStatement($stmtId);
                    if (!$sql) {
                        throw new \RuntimeException('未找到预编译语句');
                    }

                    // 使用PDO准备语句并绑定参数
                    $stmt = $pdo->prepare($sql);

                    // 绑定参数
                    foreach ($parameters as $index => $value) {
                        $paramIndex = $index + 1; // PDO参数索引从1开始
                        $stmt->bindValue($paramIndex, $value);
                    }

                    $stmt->execute();

                    // 检查是否有结果集
                    $hasResultSet = false;
                    try {
                        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                        $hasResultSet = true;
                    } catch (\PDOException $e) {
                        // 如果没有结果集（例如INSERT/UPDATE/DELETE语句），获取受影响行数
                        if (strpos($e->getMessage(), 'no result set') !== false) {
                            $affectedRows = $stmt->rowCount();
                            $lastInsertId = (int) $pdo->lastInsertId();

                            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

                            $this->logger->info('预编译语句执行成功 (无结果集)', [
                                'statement_id' => $stmtId,
                                'elapsed_ms' => $elapsedMs,
                                'affected_rows' => $affectedRows,
                                'last_insert_id' => $lastInsertId,
                            ]);

                            return $this->createOkPacket($affectedRows, $lastInsertId);
                        } else {
                            throw $e;
                        }
                    }

                    if ($hasResultSet) {
                        $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

                        $this->logger->info('预编译语句执行成功', [
                            'statement_id' => $stmtId,
                            'elapsed_ms' => $elapsedMs,
                            'result_count' => count($result),
                        ]);

                        return $this->createResultSetPackets($result);
                    }
                }
            }

            throw new \RuntimeException('无法获取PDO连接');

        } catch (\Throwable $e) {
            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->logger->error('预编译语句执行失败', [
                'statement_id' => $stmtId,
                'error' => $e->getMessage(),
                'elapsed_ms' => $elapsedMs,
            ]);

            return $this->createErrorPackets(2002, 'Execute failed: ' . $e->getMessage());
        }
    }

    /**
     * 创建预编译响应包
     */
    private function createPrepareResponsePacket(int $stmtId, \PDOStatement $stmt): array
    {
        // MySQL PREPARE 响应格式 (简化版)
        $payload = pack('V', $stmtId);        // statement_id (4 bytes)
        $payload .= pack('v', 0);             // num_columns (2 bytes) - 简化
        $payload .= pack('v', 0);             // num_params (2 bytes) - 简化
        $payload .= chr(0);                   // reserved (1 byte)
        $payload .= pack('v', 0);             // warning_count (2 bytes)

        return [Packet::create(0, $payload)];
    }

    /**
     * 检查是否是连接相关错误
     */
    private function isConnectionError(string $errorMessage): bool
    {
        $connectionErrors = [
            'Lost connection',
            'Connection refused',
            'Connection timed out',
            'Connection reset',
            'Broken pipe',
            'Network is unreachable',
            'Connection aborted',
            'MySQL server has gone away',
        ];

        foreach ($connectionErrors as $error) {
            if (stripos($errorMessage, $error) !== false) {
                return true;
            }
        }

        return false;
    }
}
