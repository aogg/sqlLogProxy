<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 监听端口
    'port' => env('PROXY_PORT', 3306),

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

    // 代理账号配置（客户端连接代理时使用的账号）
    'proxy_accounts' => [
        [
            'username' => 'proxy_user',
            'password' => 'proxy_pass', // 生产环境建议使用哈希存储
            'database' => '', // 允许连接的数据库，可为空表示不限制
        ],
        // 可以添加更多代理账号
        // [
        //     'username' => 'admin',
        //     'password' => 'admin123',
        //     'database' => 'test',
        // ],
    ],

    // 后端真实MySQL账号配置（代理使用此账号连接真实MySQL）
    'backend_mysql' => [
        'host' => env('TARGET_MYSQL_HOST', 'mysql57.common-all'),
        'port' => (int) env('TARGET_MYSQL_PORT', 3306),
        'username' => env('TARGET_MYSQL_USERNAME', 'root'),
        'password' => env('TARGET_MYSQL_PASSWORD', 'root'),
        'database' => env('TARGET_MYSQL_DATABASE', ''),
        'charset' => 'utf8mb4',
        'connect_timeout' => 5.0,
        'tls' => false, // 代理到真实MySQL的连接是否使用TLS
        'tls_ca_file' => null,
        'tls_cert_file' => null,
        'tls_key_file' => null,
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
