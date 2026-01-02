<?php

declare(strict_types=1);

// MySQL协议测试脚本
class MySQLTester
{
    private $socket;
    private $host;
    private $port;

    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function connect(): bool
    {
        echo "连接到 {$this->host}:{$this->port}...\n";

        $this->socket = fsockopen($this->host, $this->port, $errno, $errstr, 10);
        if (!$this->socket) {
            echo "连接失败: {$errstr} ({$errno})\n";
            return false;
        }

        echo "连接成功\n";
        return true;
    }

    public function readPacket(): ?string
    {
        // 读取4字节包头
        $header = fread($this->socket, 4);
        if ($header === false || strlen($header) !== 4) {
            echo "读取包头失败\n";
            return null;
        }

        // 解析长度 (小端序，3字节)
        $length = unpack('V', substr($header, 0, 3) . "\x00")[1];
        echo "包长度: {$length}\n";

        // 读取数据
        $data = fread($this->socket, $length);
        if ($data === false || strlen($data) !== $length) {
            echo "读取数据失败\n";
            return null;
        }

        return $header . $data;
    }

    public function sendPacket(string $payload, int $sequenceId = 0): bool
    {
        $length = strlen($payload);

        // 构造包头：长度(3字节，小端序) + 序列号(1字节)
        $header = pack('V', $length) & "\xff\xff\xff"; // 3字节长度
        $header .= chr($sequenceId);

        $packet = $header . $payload;

        echo "发送包，长度: {$length}, 序列号: {$sequenceId}\n";

        $result = fwrite($this->socket, $packet);
        if ($result === false) {
            echo "发送失败\n";
            return false;
        }

        return true;
    }

    public function testConnectionLoss(): void
    {
        echo "开始测试连接丢失问题...\n";

        // 读取MySQL握手包
        $handshake = $this->readPacket();
        if (!$handshake) {
            echo "读取握手包失败\n";
            return;
        }

        echo "收到握手包，长度: " . strlen($handshake) . "\n";

        // 发送认证响应（简化版本）
        // 这里使用固定的认证数据，实际应该解析握手包并构造正确的响应
        $authResponse = "\x00\x00\x00\x01\x0d\xa6\x03\x00\x00\x00\x00\x01\x21\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00root\x00\x00";
        $this->sendPacket($authResponse, 1);

        // 读取认证结果
        $authResult = $this->readPacket();
        if (!$authResult) {
            echo "读取认证结果失败\n";
            return;
        }

        echo "认证完成\n";

        // 发送查询
        $query = "SELECT VERSION()";
        $queryPayload = "\x03" . $query; // COM_QUERY命令
        $this->sendPacket($queryPayload, 0);

        // 读取查询结果
        echo "等待查询结果...\n";
        $startTime = microtime(true);

        $result = $this->readPacket();
        $endTime = microtime(true);

        if (!$result) {
            $duration = ($endTime - $startTime) * 1000;
            echo "查询失败，耗时: {$duration}ms\n";
            echo "这可能触发了'Lost connection to MySQL server during query'错误\n";
            return;
        }

        $duration = ($endTime - $startTime) * 1000;
        echo "查询成功，耗时: {$duration}ms, 结果长度: " . strlen($result) . "\n";
    }

    public function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            echo "连接已关闭\n";
        }
    }
}

// 主测试
try {
    $tester = new MySQLTester('127.0.0.1', 3317);

    if ($tester->connect()) {
        $tester->testConnectionLoss();
    }

    $tester->close();

} catch (Exception $e) {
    echo "测试异常: " . $e->getMessage() . "\n";
}

echo "测试完成\n";
