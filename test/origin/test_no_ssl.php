<?php

// 测试非SSL连接到代理的脚本
$host = '127.0.0.1';
$port = 3317;
$user = 'root';
$password = 'root';
$dbname = 'test';

echo "尝试非SSL连接到代理: $host:$port\n";

// 禁用SSL
$conn = mysqli_init();
mysqli_ssl_set($conn, null, null, null, null, null);
mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
mysqli_real_connect($conn, $host, $user, $password, $dbname, $port, null, MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);

if (!$conn) {
    echo "连接失败: " . mysqli_connect_error() . "\n";
} else {
    echo "连接成功!\n";

    $result = mysqli_query($conn, "SELECT 1 as test");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        echo "查询结果: " . $row['test'] . "\n";
        mysqli_free_result($result);
    } else {
        echo "查询失败: " . mysqli_error($conn) . "\n";
    }

    mysqli_close($conn);
}
