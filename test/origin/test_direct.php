<?php

/**
 * 直接连接到 MySQL 服务器测试
 */

echo "=== 直接连接 MySQL 服务器 ===\n\n";

try {
    $dsn = "mysql:host=mysql57.common-all;port=3306";
    $pdo = new PDO($dsn, "root", "root");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ 直接连接成功！\n\n";

    echo "测试1: 执行 SELECT 1 查询...\n";
    $stmt = $pdo->query("SELECT 1");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    echo "✅ SELECT 1 结果: " . $row[0] . "\n\n";

    echo "测试2: 执行 SELECT VERSION() 查询...\n";
    $stmt = $pdo->query("SELECT VERSION()");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    echo "✅ MySQL 版本: " . $row[0] . "\n\n";

    echo "=== 测试完成 ===\n";
} catch (PDOException $e) {
    echo "❌ 连接失败: " . $e->getMessage() . "\n";
    echo "=== 测试失败 ===\n";
}
