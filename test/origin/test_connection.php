<?php

// 测试连接到代理的脚本
$host = '127.0.0.1';
$port = 3317;
$user = 'root';
$password = 'root';
$dbname = 'test';

echo "尝试连接到代理: $host:$port\n";

$conn = mysqli_connect($host, $user, $password, $dbname, $port);

if (!$conn) {
    echo "连接失败: " . mysqli_connect_error() . "\n";
} else {
    echo "连接成功!\n";

    $result = mysqli_query($conn, "SELECT 1 as test");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        echo "查询结果: " . $row['test'] . "\n";
    } else {
        echo "查询失败: " . mysqli_error($conn) . "\n";
    }

    mysqli_close($conn);
}
