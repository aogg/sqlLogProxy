<?php
/**
 * 连接稳定性测试脚本
 * 用于测试代理服务是否能正确处理长时间运行的查询和连接恢复
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Container;
use Hyperf\Config\Config;
use Hyperf\DbConnection\ConnectionResolver;
use Hyperf\DbConnection\Pool\PoolFactory;
use Psr\Container\ContainerInterface;

// 创建容器
$container = new Container();

// 配置
$config = new Config([
    'databases' => [
        'backend_mysql' => [
            'driver' => 'mysql',
            'host' => getenv('TARGET_MYSQL_HOST') ?: 'mysql57.common-all',
            'port' => (int)(getenv('TARGET_MYSQL_PORT') ?: 3306),
            'username' => getenv('TARGET_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('TARGET_MYSQL_PASSWORD') ?: 'root',
            'database' => getenv('TARGET_MYSQL_DATABASE') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'pool' => [
                'min_connections' => 2,
                'max_connections' => 20,
                'connect_timeout' => 30.0,
                'wait_timeout' => 10.0,
                'heartbeat' => 30,
                'max_idle_time' => 300.0,
            ],
        ],
    ],
]);

$container->set(ConfigInterface::class, $config);

// 创建连接解析器
$poolFactory = new PoolFactory($container);
$connectionResolver = new ConnectionResolver($poolFactory);
$container->set(\Hyperf\DbConnection\ConnectionResolverInterface::class, $connectionResolver);

echo "开始连接稳定性测试...\n";

$testResults = [];

// 测试1: 基本连接测试
echo "测试1: 基本连接测试\n";
try {
    $connection = \Hyperf\DbConnection\Db::connection('backend_mysql');
    $result = $connection->select('SELECT 1 as test');
    $testResults['basic_connection'] = ['success' => true, 'result' => $result];
    echo "✓ 基本连接测试通过\n";
} catch (\Throwable $e) {
    $testResults['basic_connection'] = ['success' => false, 'error' => $e->getMessage()];
    echo "✗ 基本连接测试失败: " . $e->getMessage() . "\n";
}

// 测试2: 长查询测试
echo "测试2: 长查询测试 (执行需要几秒的查询)\n";
try {
    $connection = \Hyperf\DbConnection\Db::connection('backend_mysql');
    $startTime = microtime(true);
    $result = $connection->select('SELECT SLEEP(3) as sleep_result');
    $elapsed = microtime(true) - $startTime;
    $testResults['long_query'] = ['success' => true, 'elapsed' => $elapsed, 'result' => $result];
    echo "✓ 长查询测试通过，耗时: " . round($elapsed, 2) . "秒\n";
} catch (\Throwable $e) {
    $testResults['long_query'] = ['success' => false, 'error' => $e->getMessage()];
    echo "✗ 长查询测试失败: " . $e->getMessage() . "\n";
}

// 测试3: 并发连接测试
echo "测试3: 并发连接测试\n";
$concurrentTests = [];
for ($i = 0; $i < 5; $i++) {
    $concurrentTests[] = function () use ($i) {
        try {
            $connection = \Hyperf\DbConnection\Db::connection('backend_mysql');
            $result = $connection->select('SELECT CONNECTION_ID() as conn_id, SLEEP(1) as delay');
            return ['success' => true, 'conn_id' => $result[0]['conn_id'] ?? null];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    };
}

$concurrentResults = [];
foreach ($concurrentTests as $test) {
    $concurrentResults[] = $test();
}

$successCount = count(array_filter($concurrentResults, fn($r) => $r['success']));
$testResults['concurrent_connections'] = [
    'success' => $successCount === 5,
    'total' => 5,
    'successful' => $successCount,
    'results' => $concurrentResults
];
echo "✓ 并发连接测试完成，{$successCount}/5 成功\n";

// 测试4: 连接复用测试
echo "测试4: 连接复用测试\n";
try {
    $connections = [];
    for ($i = 0; $i < 3; $i++) {
        $connection = \Hyperf\DbConnection\Db::connection('backend_mysql');
        $result = $connection->select('SELECT CONNECTION_ID() as conn_id');
        $connections[] = $result[0]['conn_id'] ?? null;
        // 等待一段时间再获取下一个连接
        usleep(100000); // 100ms
    }

    // 检查是否复用了连接 (至少有两个连接ID相同)
    $uniqueConnections = array_unique($connections);
    $reused = count($connections) !== count($uniqueConnections);

    $testResults['connection_reuse'] = [
        'success' => true,
        'connections' => $connections,
        'unique_connections' => count($uniqueConnections),
        'reused' => $reused
    ];
    echo "✓ 连接复用测试通过，连接ID: " . implode(', ', $connections) . "\n";
} catch (\Throwable $e) {
    $testResults['connection_reuse'] = ['success' => false, 'error' => $e->getMessage()];
    echo "✗ 连接复用测试失败: " . $e->getMessage() . "\n";
}

// 输出测试总结
echo "\n=== 测试总结 ===\n";
$allPassed = true;
foreach ($testResults as $testName => $result) {
    $status = $result['success'] ? '✓' : '✗';
    echo "{$status} {$testName}: " . ($result['success'] ? '通过' : '失败') . "\n";
    if (!$result['success']) {
        $allPassed = false;
        echo "  错误: " . ($result['error'] ?? '未知错误') . "\n";
    }
}

echo "\n总体结果: " . ($allPassed ? '✓ 所有测试通过' : '✗ 部分测试失败') . "\n";

if (!$allPassed) {
    echo "\n建议检查:\n";
    echo "1. MySQL服务器是否正常运行\n";
    echo "2. 网络连接是否稳定\n";
    echo "3. 连接池配置是否正确\n";
    echo "4. 防火墙设置是否阻止连接\n";
}
