<?php
/**
 * 测试 USE 命令处理
 * 用于调试 "Lost connection" 问题的测试脚本
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Protocol\MySql\Packet;
use App\Protocol\MySql\Auth;

// 创建一个简单的USE命令测试
echo "=== 测试 USE 命令响应包格式 ===\n";

// 创建OK包（USE命令的响应）
$okPacket = Auth::createOkPacket();

echo "OK包信息:\n";
echo "- 序列ID: " . $okPacket->getSequenceId() . "\n";
echo "- 负载长度: " . strlen($okPacket->getPayload()) . "\n";
echo "- 负载十六进制: " . bin2hex($okPacket->getPayload()) . "\n";

// 按照MySQL协议标准分析OK包
$payload = $okPacket->getPayload();
echo "\nOK包解析:\n";
echo "- 包头 (0x00): 0x" . dechex(ord($payload[0])) . "\n";

// 读取affected_rows
$pos = 1;
$affectedRowsLen = ord($payload[$pos]);
echo "- affected_rows长度编码: 0x" . dechex($affectedRowsLen) . "\n";
if ($affectedRowsLen < 251) {
    echo "- affected_rows值: " . $affectedRowsLen . "\n";
    $pos += 1;
} else {
    // 处理更长的长度编码（这里简化处理）
    $pos += 1;
}

// 读取last_insert_id
$lastInsertIdLen = ord($payload[$pos]);
echo "- last_insert_id长度编码: 0x" . dechex($lastInsertIdLen) . "\n";
if ($lastInsertIdLen < 251) {
    echo "- last_insert_id值: " . $lastInsertIdLen . "\n";
    $pos += 1;
} else {
    $pos += 1;
}

// 读取status_flags (2字节，小端序)
$statusFlags = unpack('v', substr($payload, $pos, 2))[1];
echo "- status_flags: 0x" . dechex($statusFlags) . "\n";
$pos += 2;

// 读取warnings (2字节，小端序)
$warnings = unpack('v', substr($payload, $pos, 2))[1];
echo "- warnings: 0x" . dechex($warnings) . "\n";

echo "\n=== 标准MySQL OK包格式检查 ===\n";
echo "标准OK包应该包含:\n";
echo "- 0x00 (OK标记)\n";
echo "- affected_rows (长度编码)\n";
echo "- last_insert_id (长度编码)\n";
echo "- status_flags (2字节)\n";
echo "- warnings (2字节)\n";

$expectedLength = 1 + 1 + 1 + 2 + 2; // 基本的长度
echo "\n预期包长度: {$expectedLength} 字节\n";
echo "实际包长度: " . strlen($payload) . " 字节\n";

if (strlen($payload) === $expectedLength) {
    echo "✓ OK包长度正确\n";
} else {
    echo "✗ OK包长度不正确\n";
}

echo "\n=== USE命令特殊检查 ===\n";
echo "对于USE命令，OK包应该:\n";
echo "- affected_rows = 0\n";
echo "- last_insert_id = 0\n";
echo "- status_flags = 0 (或包含SERVER_STATUS_IN_TRANS等)\n";
echo "- warnings = 0\n";

// 检查USE命令的特殊情况
if ($affectedRowsLen === 0 && $lastInsertIdLen === 0 && $statusFlags === 0 && $warnings === 0) {
    echo "✓ USE命令OK包格式看起来正确\n";
} else {
    echo "⚠ USE命令OK包格式可能有问题\n";
    echo "建议检查status_flags是否应该设置SERVER_STATUS_AUTOCOMMIT (0x0002)\n";
}
