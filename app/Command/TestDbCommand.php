<?php

declare(strict_types=1);

namespace App\Command;

use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Hyperf\DbConnection\Db;
use Psr\Container\ContainerInterface;

/**
 * 测试数据库连接命令
 */
#[Command]
class TestDbCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('db:test');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('测试数据库连接');
    }

    public function handle()
    {
        $this->info('开始测试数据库连接...');

        try {
            // 测试 backend_mysql 连接
            $this->info('测试 backend_mysql 连接...');
            $connection = Db::connection('backend_mysql');
            $this->info('获取连接对象成功');

            // 检查连接对象的类型和可用方法
            $this->info('连接对象类型: ' . get_class($connection));
            $this->info('连接对象方法: ' . implode(', ', get_class_methods($connection)));

            // 尝试不同的方法获取 PDO
            $pdo = null;
            if (method_exists($connection, 'getConnection')) {
                $pdo = $connection->getConnection();
                $this->info('通过 getConnection 获取 PDO');
            } elseif (method_exists($connection, 'getPdo')) {
                $pdo = $connection->getPdo();
                $this->info('通过 getPdo 获取 PDO');
            } elseif (method_exists($connection, 'getReadPdo')) {
                $pdo = $connection->getReadPdo();
                $this->info('通过 getReadPdo 获取 PDO');
            } elseif (method_exists($connection, 'getWritePdo')) {
                $pdo = $connection->getWritePdo();
                $this->info('通过 getWritePdo 获取 PDO');
            } else {
                $this->error('无法获取 PDO 对象');
                return;
            }

            if ($pdo instanceof \PDO) {
                $this->info('PDO 对象有效，类型: ' . get_class($pdo));
            } else {
                $this->error('PDO 对象无效: ' . gettype($pdo) . ' 类: ' . (is_object($pdo) ? get_class($pdo) : 'N/A'));
                return;
            }

            // 执行测试查询
            $result = $connection->select('SELECT 12 as test_value, NOW() as current_time');
            $this->info('查询执行成功: ' . json_encode($result));

        } catch (\Throwable $e) {
            $this->error('数据库连接测试失败: ' . $e->getMessage());
            $this->error('文件: ' . $e->getFile() . ':' . $e->getLine());
            $this->error('Trace: ' . $e->getTraceAsString());
        }
    }
}
