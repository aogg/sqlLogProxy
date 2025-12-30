<?php

declare(strict_types=1);

namespace App\Proxy\Executor;

use Hyperf\DB\DB;
use App\Protocol\MySql\Packet;
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
     * @return array 执行结果，包含 MySQL 协议包
     */
    public function execute(string $sql): array
    {
        $startTime = microtime(true);

        $this->logger->info('开始执行后端 SQL', [
            'sql' => $sql,
        ]);

        try {
            // 使用 Hyperf 数据库连接执行 SQL
            $connection = DB::connection('backend_mysql');

            // 对于 SELECT 查询，使用 select 方法
            if (stripos(trim($sql), 'select') === 0) {
                $result = $connection->select($sql);

                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

                $this->logger->info('后端 SELECT 查询执行成功', [
                    'sql' => $sql,
                    'elapsed_ms' => $elapsedMs,
                    'result_count' => count($result),
                ]);

                return $this->createResultSetPackets($result);
            } else {
                // 对于其他查询（如 INSERT, UPDATE, DELETE），使用 statement 方法
                $affectedRows = $connection->statement($sql);
                $lastInsertId = $connection->getPdo()->lastInsertId();

                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

                $this->logger->info('后端查询执行成功', [
                    'sql' => $sql,
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
            $packets[] = Packet::create($sequenceId++, $this->createColumnDefinition($column));
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
            $connection = DB::connection('backend_mysql');
            $pool = $connection->getPool();

            return [
                'pool_type' => 'hyperf',
                'pool_name' => 'backend_mysql',
                'connections_count' => $pool->getConnectionsCount(),
                'idle_connections_count' => $pool->getIdleConnectionsCount(),
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'Failed to get pool stats: ' . $e->getMessage()
            ];
        }
    }
}
