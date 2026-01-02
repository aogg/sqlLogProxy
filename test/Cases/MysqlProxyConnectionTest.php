<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use PHPUnit\Framework\Assert;
use Swoole\Coroutine;
use Swoole\Process;

/**
 * MySQL代理连接测试
 * 验证通过代理服务连接到MySQL容器并执行查询
 */
class MysqlProxyConnectionTest extends \PHPUnit\Framework\TestCase
{
    // 代理服务器进程
    private ?Process $proxyProcess = null;

    // 代理端口
    private int $proxyPort = 3307;

    /**
     * 启动代理服务器
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 启动代理服务器进程
        $this->startProxyServer();

        // 等待代理服务器启动
        $this->waitForProxyReady();
    }

    /**
     * 停止代理服务器
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->proxyProcess !== null) {
            $this->proxyProcess->close();
            $this->proxyProcess = null;
        }
    }

    /**
     * 启动代理服务器进程
     */
    private function startProxyServer(): void
    {
        $callback = function () {
            $container = require BASE_PATH . '/config/container.php';

            // 设置环境变量，使用代理端口3307
            putenv('PROXY_PORT=' . $this->proxyPort);
            putenv('TARGET_MYSQL_HOST=mysql57.common-all');
            putenv('TARGET_MYSQL_PORT=3306');

            // 获取服务器实例
            $server = $container->get(\Hyperf\Contract\ApplicationInterface::class);

            // 设置命令行参数
            $_SERVER['argv'] = ['start'];

            // 启动服务器
            $server->run();
        };

        $this->proxyProcess = new Process($callback, false, 2, true);
        $this->proxyProcess->start();

        echo "代理服务器进程已启动，PID: " . $this->proxyProcess->pid . "\n";
    }

    /**
     * 等待代理服务器就绪
     */
    private function waitForProxyReady(): void
    {
        $maxAttempts = 30; // 最多等待30秒
        $attempt = 0;

        echo "等待代理服务器启动...\n";

        while ($attempt < $maxAttempts) {
            $attempt++;

            // 检查进程是否还在运行
            if ($this->proxyProcess && $this->proxyProcess->checkSignal(SIGTERM)) {
                echo "代理服务器进程已终止\n";
                break;
            }

            // 检查端口是否监听
            $connection = @stream_socket_client(
                "tcp://127.0.0.1:{$this->proxyPort}",
                $errno,
                $errstr,
                0.5
            );

            if ($connection !== false) {
                echo "代理服务器已就绪，端口 {$this->proxyPort} 可访问\n";
                fclose($connection);
                return;
            }

            Coroutine::sleep(1);
            echo "尝试 {$attempt}/{$maxAttempts}...\n";
        }

        throw new \RuntimeException("代理服务器在 {$maxAttempts} 秒内未就绪");
    }

    /**
     * 测试数据库连接
     */
    public function testDatabaseConnection(): void
    {
        echo "\n========== 开始测试数据库连接 ==========\n";

        try {
            // 执行简单的查询
            $result = Db::select('SELECT 1 as test');

            echo "查询结果: " . json_encode($result) . "\n";

            // 验证结果
            Assert::assertNotEmpty($result, '查询结果不应为空');
            Assert::assertEquals(1, $result[0]->test, '查询结果应为1');

            echo "✅ 数据库连接测试成功\n";
        } catch (\Throwable $e) {
            echo "❌ 数据库连接测试失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * 测试MySQL版本查询
     */
    public function testMysqlVersion(): void
    {
        echo "\n========== 开始测试MySQL版本查询 ==========\n";

        try {
            $result = Db::select('SELECT VERSION() as version');

            echo "MySQL版本: " . $result[0]->version . "\n";

            Assert::assertNotEmpty($result, '查询结果不应为空');
            Assert::assertArrayHasKey(0, $result);
            Assert::assertObjectHasAttribute('version', $result[0]);

            echo "✅ MySQL版本查询测试成功\n";
        } catch (\Throwable $e) {
            echo "❌ MySQL版本查询测试失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * 测试数据库列表查询
     */
    public function testDatabaseList(): void
    {
        echo "\n========== 开始测试数据库列表查询 ==========\n";

        try {
            $databases = Db::select('SHOW DATABASES');

            echo "数据库列表:\n";
            foreach ($databases as $db) {
                echo "  - " . $db->Database . "\n";
            }

            Assert::assertNotEmpty($databases, '应该至少有一个数据库');

            echo "✅ 数据库列表查询测试成功\n";
        } catch (\Throwable $e) {
            echo "❌ 数据库列表查询测试失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * 测试创建表并插入数据
     */
    public function testCreateTableAndInsertData(): void
    {
        echo "\n========== 开始测试创建表并插入数据 ==========\n";

        try {
            // 创建测试表
            Db::statement('DROP TABLE IF EXISTS test_proxy');
            Db::statement('
                CREATE TABLE test_proxy (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');

            echo "✅ 创建表成功\n";

            // 插入测试数据
            $id1 = Db::table('test_proxy')->insertGetId(['name' => '测试数据1']);
            $id2 = Db::table('test_proxy')->insertGetId(['name' => '测试数据2']);

            echo "✅ 插入数据成功，ID: {$id1}, {$id2}\n";

            // 查询数据
            $rows = Db::table('test_proxy')->get();

            echo "查询到 " . $rows->count() . " 条记录\n";

            Assert::assertEquals(2, $rows->count(), '应该有2条记录');
            Assert::assertEquals('测试数据1', $rows[0]->name, '第一条数据的name应该正确');
            Assert::assertEquals('测试数据2', $rows[1]->name, '第二条数据的name应该正确');

            // 清理测试表
            Db::statement('DROP TABLE IF EXISTS test_proxy');

            echo "✅ 清理测试表成功\n";
            echo "✅ 创建表并插入数据测试成功\n";
        } catch (\Throwable $e) {
            echo "❌ 创建表并插入数据测试失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * 测试事务操作
     */
    public function testTransaction(): void
    {
        echo "\n========== 开始测试事务操作 ==========\n";

        try {
            // 创建测试表
            Db::statement('DROP TABLE IF EXISTS test_transaction');
            Db::statement('
                CREATE TABLE test_transaction (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    value INT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');

            // 测试提交事务
            Db::transaction(function () {
                Db::table('test_transaction')->insert(['value' => 100]);
                Db::table('test_transaction')->insert(['value' => 200]);
            });

            $count = Db::table('test_transaction')->count();
            echo "提交后记录数: {$count}\n";
            Assert::assertEquals(2, $count, '提交事务后应该有2条记录');

            // 测试回滚事务
            try {
                Db::transaction(function () {
                    Db::table('test_transaction')->insert(['value' => 300]);
                    throw new \Exception('手动回滚');
                });
            } catch (\Exception $e) {
                // 忽略异常
            }

            $count = Db::table('test_transaction')->count();
            echo "回滚后记录数: {$count}\n";
            Assert::assertEquals(2, $count, '回滚事务后应该仍有2条记录');

            // 清理测试表
            Db::statement('DROP TABLE IF EXISTS test_transaction');

            echo "✅ 事务操作测试成功\n";
        } catch (\Throwable $e) {
            echo "❌ 事务操作测试失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * 测试复杂查询
     */
    public function testComplexQuery(): void
    {
        echo "\n========== 开始测试复杂查询 ==========\n";

        try {
            // 创建测试表
            Db::statement('DROP TABLE IF EXISTS test_complex');
            Db::statement('
                CREATE TABLE test_complex (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    category VARCHAR(50) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    status VARCHAR(20) DEFAULT \'pending\'
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');

            // 插入测试数据
            Db::table('test_complex')->insert([
                ['category' => 'A', 'amount' => 100.50, 'status' => 'completed'],
                ['category' => 'A', 'amount' => 200.75, 'status' => 'completed'],
                ['category' => 'B', 'amount' => 50.25, 'status' => 'pending'],
                ['category' => 'B', 'amount' => 150.00, 'status' => 'completed'],
                ['category' => 'A', 'amount' => 75.00, 'status' => 'pending'],
            ]);

            echo "✅ 插入测试数据成功\n";

            // 测试分组和聚合
            $result = Db::table('test_complex')
                ->select('category', Db::raw('SUM(amount) as total'), Db::raw('COUNT(*) as count'))
                ->groupBy('category')
                ->get();

            echo "分组查询结果:\n";
            foreach ($result as $row) {
                echo "  {$row->category}: 总计={$row->total}, 数量={$row->count}\n";
            }

            Assert::assertEquals(2, $result->count(), '应该有2个分组');

            // 测试条件查询
            $completed = Db::table('test_complex')
                ->where('status', 'completed')
                ->orderBy('amount', 'desc')
                ->get();

            echo "状态为completed的记录数: " . $completed->count() . "\n";
            Assert::assertEquals(3, $completed->count(), '应该有3条completed状态的记录');

            // 测试更新操作
            Db::table('test_complex')
                ->where('status', 'pending')
                ->update(['status' => 'processed']);

            $processed = Db::table('test_complex')
                ->where('status', 'processed')
                ->count();

            echo "更新为processed的记录数: {$processed}\n";
            Assert::assertEquals(2, $processed, '应该有2条processed状态的记录');

            // 清理测试表
            Db::statement('DROP TABLE IF EXISTS test_complex');

            echo "✅ 复杂查询测试成功\n";
        } catch (\Throwable $e) {
            echo "❌ 复杂查询测试失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * 测试连接超时和重试
     */
    public function testConnectionRetry(): void
    {
        echo "\n========== 开始测试连接重试 ==========\n";

        try {
            $attempts = 3;
            $success = false;

            for ($i = 1; $i <= $attempts; $i++) {
                try {
                    echo "尝试连接 {$i}/{$attempts}...\n";
                    $result = Db::select('SELECT CONNECTION_ID() as id');
                    echo "✅ 连接成功，连接ID: {$result[0]->id}\n";
                    $success = true;
                    break;
                } catch (\Throwable $e) {
                    echo "连接失败: " . $e->getMessage() . "\n";
                    if ($i < $attempts) {
                        Coroutine::sleep(1);
                    }
                }
            }

            Assert::assertTrue($success, '应该在3次尝试内连接成功');

            echo "✅ 连接重试测试成功\n";
        } catch (\Throwable $e) {
            echo "❌ 连接重试测试失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * 测试长时间连接保持
     */
    public function testLongConnection(): void
    {
        echo "\n========== 开始测试长时间连接保持 ==========\n";

        try {
            $iterations = 5;
            $delay = 2; // 秒

            for ($i = 1; $i <= $iterations; $i++) {
                $result = Db::select('SELECT NOW() as time, CONNECTION_ID() as id');
                echo "查询 {$i}/{$iterations}: 时间={$result[0]->time}, 连接ID={$result[0]->id}\n";

                if ($i < $iterations) {
                    Coroutine::sleep($delay);
                }
            }

            echo "✅ 长时间连接保持测试成功\n";
        } catch (\Throwable $e) {
            echo "❌ 长时间连接保持测试失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}
