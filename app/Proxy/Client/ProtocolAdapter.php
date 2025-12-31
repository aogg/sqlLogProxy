<?php

declare(strict_types=1);

namespace App\Proxy\Client;

use App\Protocol\ConnectionContext;
use App\Proxy\Executor\BackendExecutor;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * 协议适配器
 * 根据客户端类型调整协议处理方式
 */
class ProtocolAdapter
{
    private LoggerInterface $logger;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('protocol_adapter');
    }

    /**
     * 根据客户端类型调整EOF包格式
     */
    public function adjustEofPacket(ConnectionContext $context, string $eofPacket): string
    {
        return match ($context->getClientType()) {
            ClientType::JAVA_CONNECTOR => $this->createJavaCompatibleEof(),
            ClientType::PHP_PDO => $this->createPhpCompatibleEof(),
            ClientType::MYSQL_CLIENT => $this->createMySQLCompatibleEof(),
            default => $eofPacket, // 使用默认格式
        };
    }

    /**
     * 根据客户端类型调整结果集格式
     */
    public function adjustResultSet(ConnectionContext $context, array $result): array
    {
        $this->logger->debug('根据客户端类型调整结果集格式', [
            'client_id' => $context->getClientId(),
            'client_type' => $context->getClientType()->value,
            'result_count' => count($result),
        ]);

        return match ($context->getClientType()) {
            ClientType::JAVA_CONNECTOR => $this->formatForJavaConnector($result),
            ClientType::PHP_PDO => $this->formatForPhpPdo($result),
            default => $result,
        };
    }

    /**
     * Java Connector/J 兼容的EOF包
     */
    private function createJavaCompatibleEof(): string
    {
        // Java Connector/J 期望完整的EOF包：0xfe + warning_count + status_flags
        $eof = "\xfe\x00\x00\x00\x22"; // 0xfe, warning_count=0, status_flags=0x0022
        $this->logger->debug('生成 Java Connector/J 兼容的EOF包', [
            'eof_hex' => bin2hex($eof),
        ]);
        return $eof;
    }

    /**
     * PHP PDO 兼容的EOF包
     */
    private function createPhpCompatibleEof(): string
    {
        // 使用标准的 5 字节 EOF 包格式（MySQL 4.1+）
        // 0xfe + 2字节警告计数(小端序) + 2字节状态标志(小端序)
        // 状态标志 0x0002 表示 SERVER_STATUS_AUTOCOMMIT
        $eof = "\xfe\x00\x00\x02\x00";
        $this->logger->debug('生成 PHP PDO 兼容的EOF包', [
            'eof_hex' => bin2hex($eof),
            'eof_length' => strlen($eof),
            'warning_count' => 0,
            'status_flags' => 0x0002,
        ]);
        return $eof;
    }

    /**
     * 原生MySQL客户端兼容的EOF包
     */
    private function createMySQLCompatibleEof(): string
    {
        // 某些老版本的MySQL客户端可能接受简单格式
        $eof = "\xfe";
        $this->logger->debug('生成 MySQL 原生客户端兼容的EOF包', [
            'eof_hex' => bin2hex($eof),
        ]);
        return $eof;
    }

    /**
     * 为Java Connector/J格式化结果集
     */
    private function formatForJavaConnector(array $result): array
    {
        // Java Connector/J 对某些数据类型有特殊要求
        // 这里可以添加特定的格式化逻辑
        $this->logger->debug('为 Java Connector/J 格式化结果集');
        return $result;
    }

    /**
     * 为PHP PDO格式化结果集
     */
    private function formatForPhpPdo(array $result): array
    {
        // PHP PDO 的格式化逻辑
        $this->logger->debug('为 PHP PDO 格式化结果集');
        return $result;
    }

    /**
     * 根据客户端类型调整握手包
     */
    public function adjustHandshakePacket(ConnectionContext $context, string $handshakePacket): string
    {
        // 可以根据客户端类型调整服务器版本字符串等
        $this->logger->debug('根据客户端类型调整握手包', [
            'client_id' => $context->getClientId(),
            'client_type' => $context->getClientType()->value,
        ]);

        return $handshakePacket;
    }
}
