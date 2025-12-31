<?php

declare(strict_types=1);

namespace App\Proxy\Executor;

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

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('backend');

        $this->logger->info('后端执行器初始化，使用 Hyperf 数据库连接池');
    }

    /**
     * 执行 SQL 语句
     *
     * @param string $sql 要执行的 SQL 语句
     * @param string|null $database 要使用的数据库名
     * @return array 执行结果，包含 MySQL 协议包
     */
    public function execute(string $sql, ?string $database = null): array
    {
        $startTime = microtime(true);

        $this->logger->info('开始执行后端 SQL', [
            'sql' => $sql,
        ]);

        try {
            // 使用 Hyperf 数据库连接执行 SQL
            /** @var \Hyperf\DbConnection\Connection $connection */
            $connection = DB::connection('backend_mysql');

            // 如果指定了数据库，先切换到该数据库
            if ($database !== null && $database !== '') {
                $connection->statement("USE `{$database}`");
                $this->logger->debug('已切换到数据库', [
                    'database' => $database,
                ]);
            }

            // 对于 SELECT 查询，使用 select 方法
            // 支持前置注释（例如 MySQL Connector/J 会在前面带注释），所以使用正则去除注释后判断
            if (preg_match('/^\s*(?:\/\*[\s\S]*?\*\/\s*)*(select)\b/i', $sql) === 1) {
                $result = $connection->select($sql);
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
                $affectedRows = $connection->statement($sql);
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

                // 识别SQL语句类型
                $sqlType = 'UNKNOWN';
                if (preg_match('/^\s*(?:\/\*[\s\S]*?\*\/\s*)*(insert)\b/i', $sql)) {
                    $sqlType = 'INSERT';
                } elseif (preg_match('/^\s*(?:\/\*[\s\S]*?\*\/\s*)*(update)\b/i', $sql)) {
                    $sqlType = 'UPDATE';
                } elseif (preg_match('/^\s*(?:\/\*[\s\S]*?\*\/\s*)*(delete)\b/i', $sql)) {
                    $sqlType = 'DELETE';
                } elseif (preg_match('/^\s*(?:\/\*[\s\S]*?\*\/\s*)*(create|alter|drop)\b/i', $sql)) {
                    $sqlType = 'DDL';
                }

                $this->logger->info('后端查询执行成功', [
                    'sql' => $sql,
                    'sql_type' => $sqlType,
                    'elapsed_ms' => $elapsedMs,
                    'affected_rows' => $affectedRows,
                    'last_insert_id' => $lastInsertId,
                ]);

                return $this->createOkPacket($affectedRows, $lastInsertId);
            }

        } catch (\Throwable $e) {
            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->logger->error('后端执行器异常', [
                'sql' => $sql,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'elapsed_ms' => $elapsedMs,
            ]);

            return $this->createErrorPackets(2000, 'Backend execution failed: ' . $e->getMessage());
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
        $packets[] = Packet::create($sequenceId++, chr(0xfe)); // EOF

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
        $packets[] = Packet::create($sequenceId++, chr(0xfe)); // EOF

        return $packets;
    }

    /**
     * 创建空结果集
     */
    private function createEmptyResultSet(): array
    {
        // Column count: 0
        $packets = [Packet::create(0, $this->encodeLength(0))];

        // EOF
        $packets[] = Packet::create(1, chr(0xfe));

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
        $payload .= chr(0x0c); // length of fixed fields
        $payload .= pack('v', 33); // charset (utf8mb4)
        $payload .= pack('V', 255); // max column length
        $payload .= chr(0xfd); // type (VAR_STRING)
        $payload .= pack('v', 0); // flags
        $payload .= chr(0); // decimals

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
                return [
                    'pool_type' => 'hyperf',
                    'pool_name' => 'backend_mysql',
                    'connections_count' => $pool->getConnectionsCount(),
                    'idle_connections_count' => $pool->getIdleConnectionsCount(),
                ];
            }

            // 如果没有 pool 支持，则返回基础信息
            return [
                'pool_type' => 'hyperf',
                'pool_name' => 'backend_mysql',
                'connections_count' => null,
                'idle_connections_count' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'Failed to get pool stats: ' . $e->getMessage()
            ];
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
}
