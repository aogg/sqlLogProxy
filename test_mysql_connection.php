<?php

// 测试MySQL连接的脚本
echo "开始测试MySQL连接...\n";

try {
    // 连接到代理服务
    $host = '127.0.0.1';
    $port = 3317; // 外部映射端口

    echo "尝试连接到代理服务: {$host}:{$port}\n";

    $socket = fsockopen($host, $port, $errno, $errstr, 10);
    if (!$socket) {
        echo "连接失败: {$errstr} ({$errno})\n";
        exit(1);
    }

    echo "成功连接到代理服务\n";

    // 发送MySQL握手响应（模拟客户端认证）
    // 这里需要构造正确的MySQL协议包
    // 为了简化，我们发送一个简单的查询

    // 关闭连接
    fclose($socket);
    echo "连接已关闭\n";

} catch (Exception $e) {
    echo "异常: " . $e->getMessage() . "\n";
}

echo "测试完成\n";
