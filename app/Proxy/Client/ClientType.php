<?php

declare(strict_types=1);

namespace App\Proxy\Client;

/**
 * 客户端类型枚举
 */
enum ClientType: string
{
    case JAVA_CONNECTOR = 'java_connector';
    case PHP_PDO = 'php_pdo';
    case MYSQL_CLIENT = 'mysql_client';
    case UNKNOWN = 'unknown';

    /**
     * 获取客户端类型的描述
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::JAVA_CONNECTOR => 'Java MySQL Connector/J',
            self::PHP_PDO => 'PHP PDO',
            self::MYSQL_CLIENT => 'MySQL 原生客户端',
            self::UNKNOWN => '未知客户端',
        };
    }

    public function checkAuth($storedPassword, $authPluginData)
    {
        return match ($this) {
            self::JAVA_CONNECTOR => \App\Helpers\Mysql\SecurityHelper::scramble411_new($storedPassword, $authPluginData),
            self::PHP_PDO => \App\Helpers\Mysql\SecurityHelper::calculateAuthResponse($storedPassword, $authPluginData),
            self::MYSQL_CLIENT => \App\Helpers\Mysql\SecurityHelper::calculateAuthResponse($storedPassword, $authPluginData),
            self::UNKNOWN => false,
        };
    }
}
