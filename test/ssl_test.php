<?php

// SSL连接测试脚本 - 测试新的代理认证机制
echo "测试SSL连接到MySQL代理（代理认证模式）...\n";

// 配置
$proxyHost = '127.0.0.1';
$proxyPort = 3317;
$proxyUser = 'proxy_user';  // 代理账号
$proxyPass = 'proxy_pass';  // 代理密码
$database = 'test';

echo "连接配置:\n";
echo "- 代理主机: {$proxyHost}:{$proxyPort}\n";
echo "- 代理账号: {$proxyUser}\n";
echo "- 数据库: {$database}\n";
echo "- SSL模式: REQUIRED\n\n";

// 使用PDO连接到代理服务器，启用SSL
try {
    $dsn = "mysql:host={$proxyHost};port={$proxyPort};dbname={$database};charset=utf8mb4";
    $options = [
        PDO::MYSQL_ATTR_SSL_CA => null, // 不验证CA
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false, // 不验证服务器证书
        PDO::MYSQL_ATTR_SSL_KEY => null,
        PDO::MYSQL_ATTR_SSL_CERT => null,
    ];

    echo "正在连接代理服务器...\n";
    $pdo = new PDO($dsn, $proxyUser, $proxyPass, $options);
    echo "✓ SSL连接和代理认证成功！\n";

    // 执行一个简单的查询
    echo "\n执行测试查询...\n";
    $stmt = $pdo->query('SELECT VERSION() as version, USER() as user, DATABASE() as current_db');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "✓ 查询执行成功\n";
    echo "- MySQL版本: " . $result['version'] . "\n";
    echo "- 当前用户: " . $result['user'] . "\n";
    echo "- 当前数据库: " . $result['current_db'] . "\n";

    // 测试更多的查询
    echo "\n执行更多测试查询...\n";

    // SELECT 查询
    $stmt = $pdo->query('SELECT 1 as test_value, "Hello Proxy!" as message');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "✓ SELECT查询: value={$result['test_value']}, message={$result['message']}\n";

    // SHOW TABLES 查询
    $stmt = $pdo->query('SHOW TABLES LIMIT 5');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ SHOW TABLES查询: 找到 " . count($tables) . " 个表\n";

    // 测试错误处理
    echo "\n测试错误处理...\n";
    try {
        $pdo->query('SELECT * FROM nonexistent_table_12345');
        echo "✗ 错误：应该抛出异常\n";
    } catch (PDOException $e) {
        echo "✓ 错误处理正常: " . substr($e->getMessage(), 0, 50) . "...\n";
    }

} catch (PDOException $e) {
    echo "✗ 连接或查询失败: " . $e->getMessage() . "\n";

    // 提供故障排除建议
    echo "\n故障排除建议:\n";
    if (strpos($e->getMessage(), 'Connection refused') !== false) {
        echo "- 确保代理服务正在运行 (php bin/hyperf.php start)\n";
        echo "- 检查代理端口 {$proxyPort} 是否被占用\n";
    } elseif (strpos($e->getMessage(), 'Access denied') !== false) {
        echo "- 检查代理账号和密码是否正确\n";
        echo "- 确认账号在 config/autoload/proxy.php 中已配置\n";
    } elseif (strpos($e->getMessage(), 'SSL') !== false) {
        echo "- 检查SSL证书文件是否存在: runtime/certs/server.crt 和 server.key\n";
        echo "- 确保证书文件对php进程可读\n";
    } else {
        echo "- 检查后端MySQL服务器是否可访问\n";
        echo "- 查看代理日志了解详细错误信息\n";
    }
}

echo "\n测试完成。\n";

