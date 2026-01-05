<?php

declare(strict_types=1);

namespace App\Proxy\Client;

use App\Protocol\ConnectionContext;
use App\Protocol\MySql\Packet;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * 客户端检测器
 * 根据客户端的特征信息识别客户端类型
 */
class ClientDetector
{
    private LoggerInterface $logger;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('client_detector');
    }

    /**
     * 从握手信息检测客户端类型
     */
    public function detectFromHandshake(Packet $packet, ConnectionContext $context, array $handshakeData): void
    {
        $capabilities = $handshakeData['capabilities'] ?? 0;
        $charset = $handshakeData['charset'] ?? 0;

        $this->logger->info('开始从握手信息检测客户端类型', [
            'client_id' => $context->getClientId(),
            'capabilities' => sprintf('0x%08x', $capabilities),
            'charset' => $charset,
        ]);

        $detectedType = $packet->analyzeCapabilities($charset);

        if ($detectedType !== ClientType::UNKNOWN) {
            $context->setClientType($detectedType);
            $this->logger->info('从握手信息识别到客户端类型', [
                'client_id' => $context->getClientId(),
                'client_type' => $detectedType->value,
                'client_description' => $detectedType->getDescription(),
                'detection_method' => 'handshake_capabilities',
            ]);
        }

        $context->setClientCapabilities([
            'capabilities' => $capabilities,
            'charset' => $charset,
        ]);
    }

    /**
     * 从SQL查询检测客户端类型和版本
     */
    public function detectFromQuery(ConnectionContext $context, string $sql): void
    {
        // 如果已经识别出客户端类型，跳过
        if ($context->getClientType() !== ClientType::UNKNOWN) {
            return;
        }

        $this->logger->debug('开始从SQL查询检测客户端类型', [
            'client_id' => $context->getClientId(),
            'sql_preview' => substr($sql, 0, 100),
        ]);

        // 检测Java Connector/J的注释
        if (preg_match('/\/\* mysql-connector-j-([\d.]+).*?\*\//i', $sql, $matches)) {
            $context->setClientType(ClientType::JAVA_CONNECTOR);
            $context->setClientVersion($matches[1]);
            $this->logger->info('从查询注释识别到 Java Connector/J', [
                'client_id' => $context->getClientId(),
                'version' => $matches[1],
                'detection_method' => 'query_comment',
                'full_comment' => $matches[0],
            ]);
            return;
        }

        // 检测PHP PDO的特征（通常没有特殊注释，但可能有特定的查询模式）
        if ($this->isLikelyPhpPdo($sql)) {
            $context->setClientType(ClientType::PHP_PDO);
            $this->logger->info('从查询模式识别到 PHP PDO', [
                'client_id' => $context->getClientId(),
                'detection_method' => 'query_pattern',
            ]);
            return;
        }

        // 检测MySQL原生客户端
        if ($this->isLikelyMySQLClient($sql)) {
            $context->setClientType(ClientType::MYSQL_CLIENT);
            $this->logger->info('从查询模式识别到 MySQL 原生客户端', [
                'client_id' => $context->getClientId(),
                'detection_method' => 'query_pattern',
            ]);
        }
    }

    /**
     * 从连接属性检测客户端类型
     */
    public function detectFromConnectionAttributes(ConnectionContext $context, array $attributes): void
    {
        if ($context->getClientType() !== ClientType::UNKNOWN) {
            return;
        }

        $this->logger->debug('开始从连接属性检测客户端类型', [
            'client_id' => $context->getClientId(),
            'attributes' => $attributes,
        ]);

        // 检查程序名
        $programName = $attributes['_client_name'] ?? $attributes['program_name'] ?? '';

        if (stripos($programName, 'mysql-connector-java') !== false) {
            $context->setClientType(ClientType::JAVA_CONNECTOR);
            $this->logger->info('从连接属性识别到 Java Connector', [
                'client_id' => $context->getClientId(),
                'program_name' => $programName,
                'detection_method' => 'connection_attributes',
            ]);
        } elseif (stripos($programName, 'pdo') !== false) {
            $context->setClientType(ClientType::PHP_PDO);
            $this->logger->info('从连接属性识别到 PHP PDO', [
                'client_id' => $context->getClientId(),
                'program_name' => $programName,
                'detection_method' => 'connection_attributes',
            ]);
        }
    }

    /**
     * 判断是否可能是PHP PDO
     */
    private function isLikelyPhpPdo(string $sql): bool
    {
        // PHP PDO 通常发送简单的查询，没有特殊的注释
        // 这里可以根据查询模式进行判断
        return preg_match('/^SELECT\s+@@/i', trim($sql)) === 1;
    }

    /**
     * 判断是否可能是MySQL原生客户端
     */
    private function isLikelyMySQLClient(string $sql): bool
    {
        // MySQL客户端通常发送简单的命令
        return preg_match('/^(SELECT|SHOW|SET)\s/i', trim($sql)) === 1;
    }

    /**
     * 获取客户端类型统计信息
     */
    public function getClientTypeStats(): array
    {
        // 这里可以实现客户端类型统计逻辑
        return [
            'total_clients' => 0,
            'type_breakdown' => [],
        ];
    }
}
