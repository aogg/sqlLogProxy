<?php

/**
 * 测试 MySQL 代理服务
 */

echo "=== 测试 MySQL 代理服务 ===\n\n";

$pdo = null;

// 测试1: 连接到代理
echo "测试1: 连接到代理服务（使用 proxy_user 账号）...\n";
try {
    $dsn = "mysql:host=127.0.0.1;port=3309;dbname=mysql";
    $pdo = new PDO($dsn, "root", "root");
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

// 测试2.1: 执行简单的 SELECT 1 查询
echo "测试2.1: 执行 SET NAMES utf8mb4 查询...\n";
try {
    $row = $pdo->exec("SET NAMES utf8mb4");
    echo "✅ SELECT 1.1 结果: " . var_export($row, true) . "\n\n";
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

// 测试4: 执行查询 db
echo "测试4: 执行 MySQL库的db表 查询...\n";
try {
    $stmt = $pdo->query("SELECT * from mysql.db");
    $row = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ MySQL库的db表: " . var_export(count($row), true) . "\n\n";
} catch (PDOException $e) {
    echo "❌ 查询失败: " . $e->getMessage() . "\n\n";
}

// 测试4: 执行查询 db
echo "测试4.1: 执行 MySQL库的db表  无库名 查询...\n";
try {
    $stmt = $pdo->query("SELECT * from db");
    $row = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ MySQL库的db表: " . var_export(count($row), true) . "\n\n";
} catch (PDOException $e) {
    echo "❌ 查询失败: " . $e->getMessage() . "\n\n";
}

// 测试5: 执行查询 db
echo "测试5: 执行 use information_schema 查询...\n";
try {
    $row = $pdo->exec("use information_schema");
    echo "✅ use mysql: " . var_export($row, true) . "\n\n";
} catch (PDOException $e) {
    echo "❌ 查询失败: " . $e->getMessage() . "\n\n";
}

// 测试6: 执行查询 db
echo "测试6: 执行 information_schema 库的 TABLES 表 查询...\n";
try {
    $stmt = $pdo->query("SELECT * from TABLES limit 200");
    $row = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ information_schema 库的 TABLES 表: " . var_export(count($row), true) . "\n\n";
} catch (PDOException $e) {
    echo "❌ 查询失败: " . $e->getMessage() . "\n\n";
}

echo "=== 测试完成 ===\n";
