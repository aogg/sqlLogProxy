<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 监听端口
    'port' => env('PROXY_PORT', 3306),

    // 目标MySQL服务器配置
    'target' => [
        'host' => env('TARGET_MYSQL_HOST', 'mysql57.common-all'),
        'port' => env('TARGET_MYSQL_PORT', 3306),
    ],

    // 日志配置
    'log' => [
        'enabled' => true,
        'path' => BASE_PATH . '/runtime',
        'date_format' => 'Ym/d/H-i',
        'sql_highlight' => true,
    ],

    // SQL过滤规则
    'filters' => [
        // 通配符过滤（不记录的SQL模式）
        'exclude_patterns' => [
            // 'SELECT * FROM information_schema*',
            // 'SHOW *',
        ],
    ],
];
