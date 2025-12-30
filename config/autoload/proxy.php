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
        'connect_timeout' => 5.0,
        'tls' => true, // 是否对目标MySQL使用TLS
        'tls_ca_file' => null, // CA证书文件路径
        'tls_cert_file' => null, // 客户端证书文件路径
        'tls_key_file' => null, // 客户端私钥文件路径
    ],

    // 连接池配置
    'pool' => [
        'size' => 12, // 每Worker连接池大小
        'idle_timeout' => 300.0, // 空闲连接超时时间（秒）
    ],

    // TLS服务器配置（用于客户端TLS终止）
    'tls' => [
        'server_cert' => BASE_PATH . '/runtime/certs/server.crt', // 服务器证书
        'server_key' => BASE_PATH . '/runtime/certs/server.key', // 服务器私钥
        'ca_cert' => null, // CA证书（用于客户端证书验证）
        'require_client_cert' => false, // 是否要求客户端证书
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
