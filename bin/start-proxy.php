#!/usr/bin/env php
<?php
/**
 * 直接启动 MySQL 协议代理服务器
 */
ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
ini_set('memory_limit', '1G');

error_reporting(E_ALL);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';

! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', Hyperf\Engine\DefaultOption::hookFlags());

use Hyperf\Di\ClassLoader;
use Hyperf\Context\ApplicationContext;
use Psr\Log\LoggerInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Contract\ApplicationInterface;
use function Hyperf\Support\env;

// 加载类
ClassLoader::init();

// 获取容器
$container = require BASE_PATH . '/config/container.php';

// 获取日志记录器
$logger = $container->get(LoggerFactory::class)->get('proxy');

try {
    // 记录启动日志
    $logger->info('正在启动MySQL协议代理服务器...');
    $port = env('PROXY_PORT', 3307);
    $logger->info('监听端口: ' . $port);
    $logger->info('按 Ctrl+C 停止服务器');

    // 直接启动 Swoole 服务器
    $application = $container->get(ApplicationInterface::class);

    $logger->info('服务器已启动，正在运行...');

    // 运行服务器（这里会阻塞，直到服务器停止）
    // 模拟命令行参数 "start"
    $_SERVER['argv'][] = 'start';
    $application->run();
    
    // 这行代码通常不会执行，除非服务器被正常关闭
    $logger->info('服务器已停止');

} catch (\Throwable $e) {
    $logger->error('启动服务器时发生错误', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    exit(1);
}
