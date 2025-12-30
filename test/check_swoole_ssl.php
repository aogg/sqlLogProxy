<?php

echo "=== MySQL TLS 代理环境检查 ===\n\n";

// 检查Swoole SSL支持
echo "1. 检查Swoole SSL支持...\n";

if (defined('SWOOLE_SSL')) {
    echo "   ✓ SWOOLE_SSL常量存在: " . SWOOLE_SSL . "\n";
} else {
    echo "   ✗ SWOOLE_SSL常量不存在\n";
}

echo "   Swoole版本: " . swoole_version() . "\n";

$ssl_support = false;
if (extension_loaded('swoole')) {
    $ssl_support = defined('SWOOLE_SSL') && SWOOLE_SSL;
}

echo "   SSL支持: " . ($ssl_support ? '✓ 是' : '✗ 否') . "\n";

// 检查证书文件
echo "\n2. 检查SSL证书文件...\n";

$cert_files = [
    'runtime/certs/server.crt' => '服务器证书',
    'runtime/certs/server.key' => '服务器私钥',
];

$cert_ok = true;
foreach ($cert_files as $file => $desc) {
    if (file_exists($file)) {
        if (is_readable($file)) {
            echo "   ✓ {$desc}存在且可读: {$file}\n";
        } else {
            echo "   ✗ {$desc}存在但不可读: {$file}\n";
            $cert_ok = false;
        }
    } else {
        echo "   ✗ {$desc}不存在: {$file}\n";
        $cert_ok = false;
    }
}

// 检查配置文件
echo "\n3. 检查配置文件...\n";

$config_checks = [
    'config/autoload/proxy.php' => '代理配置文件',
    'config/autoload/server.php' => '服务器配置文件',
];

$config_ok = true;
foreach ($config_checks as $file => $desc) {
    if (file_exists($file)) {
        echo "   ✓ {$desc}存在: {$file}\n";
    } else {
        echo "   ✗ {$desc}不存在: {$file}\n";
        $config_ok = false;
    }
}

// 检查代理端口
echo "\n4. 检查代理端口...\n";

$proxy_port = 3317;
$connection = @fsockopen('127.0.0.1', $proxy_port, $errno, $errstr, 1);
if ($connection) {
    fclose($connection);
    echo "   ✓ 代理端口 {$proxy_port} 可连接\n";
    $port_ok = true;
} else {
    echo "   ✗ 代理端口 {$proxy_port} 不可连接 ({$errstr})\n";
    $port_ok = false;
}

// 检查后端MySQL连接
echo "\n5. 检查后端MySQL连接...\n";

$backend_config = [
    'host' => getenv('TARGET_MYSQL_HOST') ?: 'mysql57.common-all',
    'port' => (int)(getenv('TARGET_MYSQL_PORT') ?: 3306),
    'username' => getenv('TARGET_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('TARGET_MYSQL_PASSWORD') ?: 'root',
];

$mysql_ok = false;
if (extension_loaded('pdo_mysql')) {
    try {
        $dsn = "mysql:host={$backend_config['host']};port={$backend_config['port']};charset=utf8mb4";
        $pdo = new PDO($dsn, $backend_config['username'], $backend_config['password'], [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $stmt = $pdo->query('SELECT VERSION() as version');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "   ✓ 后端MySQL连接成功: {$result['version']}\n";
        $mysql_ok = true;
    } catch (PDOException $e) {
        echo "   ✗ 后端MySQL连接失败: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ✗ PDO MySQL扩展未加载\n";
}

// 检查代理账号配置
echo "\n6. 检查代理账号配置...\n";

$proxy_accounts_ok = false;
if (file_exists('config/autoload/proxy.php')) {
    try {
        // 简单的配置检查（实际应该解析PHP文件）
        $config_content = file_get_contents('config/autoload/proxy.php');
        if (strpos($config_content, 'proxy_accounts') !== false) {
            echo "   ✓ 代理账号配置存在\n";
            $proxy_accounts_ok = true;
        } else {
            echo "   ✗ 未找到代理账号配置\n";
        }

        if (strpos($config_content, 'backend_mysql') !== false) {
            echo "   ✓ 后端MySQL配置存在\n";
        } else {
            echo "   ✗ 未找到后端MySQL配置\n";
        }
    } catch (Exception $e) {
        echo "   ✗ 读取配置文件失败: " . $e->getMessage() . "\n";
    }
}

// 总结
echo "\n=== 环境检查总结 ===\n";

$all_ok = $ssl_support && $cert_ok && $config_ok && $mysql_ok && $proxy_accounts_ok;

if ($all_ok) {
    echo "✓ 所有检查通过！代理服务应该可以正常运行。\n";
    echo "\n运行代理服务:\n";
    echo "  php bin/hyperf.php start\n";
    echo "\n测试代理连接:\n";
    echo "  php test/ssl_test.php\n";
} else {
    echo "✗ 部分检查失败，请修复上述问题后再运行代理服务。\n";

    if (!$ssl_support) {
        echo "\n解决方案 - SSL支持:\n";
        echo "  确保安装了支持SSL的Swoole版本\n";
        echo "  Ubuntu/Debian: apt-get install php-swoole\n";
        echo "  CentOS/RHEL: yum install php-swoole\n";
    }

    if (!$cert_ok) {
        echo "\n解决方案 - SSL证书:\n";
        echo "  创建目录: mkdir -p runtime/certs\n";
        echo "  生成自签名证书:\n";
        echo "    openssl req -x509 -newkey rsa:4096 -keyout runtime/certs/server.key -out runtime/certs/server.crt -days 365 -nodes -subj '/CN=localhost'\n";
    }
}

echo "\n检查完成。\n";