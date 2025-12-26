#!/usr/bin/env php
<?php
/**
 * MySQL代理连接测试脚本
 * 用于验证通过代理服务器连接到MySQL容器的功能
 */

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
ini_set('memory_limit', '1G');

error_reporting(E_ALL);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';

! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', Hyperf\Engine\DefaultOption::hookFlags());

use Swoole\Runtime;

// 启用协程
Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

// 加载测试环境配置
if (file_exists(BASE_PATH . '/.env.testing')) {
    $lines = file(BASE_PATH . '/.env.testing', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
    echo "已加载测试环境配置: .env.testing\n";
} else {
    echo "警告: 未找到 .env.testing 文件，使用当前环境配置\n";
}

echo "========================================\n";
echo "MySQL代理连接测试\n";
echo "========================================\n";

echo "\n测试配置:\n";
echo "  代理端口: " . getenv('PROXY_PORT', 3307) . "\n";
echo "  目标MySQL: " . getenv('TARGET_MYSQL_HOST', 'mysql57.common-all') . ":" . getenv('TARGET_MYSQL_PORT', 3306) . "\n";
echo "  数据库: " . getenv('DB_DATABASE', 'mysql') . "\n";
echo "  用户: " . getenv('DB_USERNAME', 'root') . "\n";
echo "\n========================================\n\n";

// 运行PHPUnit测试
$command = sprintf(
    'vendor/bin/co-phpunit --prepend test/bootstrap.php --colors=always --filter=MysqlProxyConnectionTest %s',
    implode(' ', array_slice($argv, 1))
);

echo "执行测试命令: {$command}\n\n";
passthru($command);
