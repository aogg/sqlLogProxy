<?php

/**
 * 测试 MySQL 代理服务
 */

echo "=== 测试 MySQL 代理服务 ===\n\n";

$pdo = null;

// 测试1: 连接到代理
echo "测试1: 连接到代理服务（使用 proxy_user 账号）...\n";
try {
    $dsn = "mysql:host=127.0.0.1;port=3308";
    $pdo = new PDO($dsn, "proxy_user", "proxy_pass");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ 通过代理连接成功！\n\n";
} catch (PDOException $e) {
    echo "❌ 连接失败: " . $e->getMessage() . "\n\n";
    echo "=== 测试失败 ===\n";
    exit(1);
}

// 测试2: 执行简单的 SELECT 1 查询
echo "测试2: 执行 SELECT 1 查询...\n";
try {
    $stmt = $pdo->query("SELECT 1");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    echo "✅ SELECT 1 结果: " . $row[0] . "\n\n";
} catch (PDOException $e) {
    echo "❌ 查询失败: " . $e->getMessage() . "\n\n";
}

// 测试3: 执行查询
echo "测试3: 执行 SELECT VERSION() 查询...\n";
try {
    $stmt = $pdo->query("SELECT VERSION()");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    echo "✅ MySQL 版本: " . $row[0] . "\n\n";
} catch (PDOException $e) {
    echo "❌ 查询失败: " . $e->getMessage() . "\n\n";
}

echo "=== 测试完成 ===\n";
