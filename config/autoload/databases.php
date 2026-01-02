<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use function Hyperf\Support\env;

return [
    'default' => [
        'driver' => env('DB_DRIVER', 'mysql'),
        'host' => env('DB_HOST', 'localhost'),
        'database' => env('DB_DATABASE', 'hyperf'),
        'port' => env('DB_PORT', 3306),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8'),
        'collation' => env('DB_COLLATION', 'utf8_unicode_ci'),
        'prefix' => env('DB_PREFIX', ''),
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
        ],
        'commands' => [
            'gen:model' => [
                'path' => 'app/Model',
                'force_casts' => true,
                'inheritance' => 'Model',
            ],
        ],
    ],
    'backend_mysql' => [
        'driver' => env('TARGET_DB_DRIVER', 'mysql'),
        'host' => env('TARGET_MYSQL_HOST', '127.0.0.1'),
        'database' => env('TARGET_MYSQL_DATABASE', ''),
        'port' => env('TARGET_MYSQL_PORT', 3306),
        'username' => env('TARGET_MYSQL_USERNAME', 'root'),
        'password' => env('TARGET_MYSQL_PASSWORD', 'root'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'pool' => [
            'min_connections' => 3, // 增加最小连接数，确保有足够的活跃连接
            'max_connections' => 25, // 增加最大连接数
            'connect_timeout' => 30.0, // 连接超时时间
            'wait_timeout' => 15.0, // 增加等待超时时间
            'heartbeat' => 15, // 更频繁的心跳检测，每15秒检查一次连接状态
            'max_idle_time' => 180.0, // 减少最大空闲时间到3分钟，避免连接被MySQL服务器断开
        ],
        'options' => [
            // MySQL连接选项，保持连接活跃
            \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION wait_timeout=28800, interactive_timeout=28800', // 设置会话超时为8小时
            \PDO::ATTR_PERSISTENT => false, // 不使用持久连接，避免连接状态问题
        ],
        'commands' => [
            'gen:model' => [
                'path' => 'app/Model',
                'force_casts' => true,
                'inheritance' => 'Model',
            ],
        ],
    ],
];
