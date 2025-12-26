<?php

declare(strict_types=1);

namespace App\Helpers;

class DsnParserHelper
{
    public static function parse(string $dsn): array
    {
        $result = [
            'host' => null,
            'port' => 3306,
            'database' => null,
            'user' => null,
            'password' => null,
            'charset' => 'utf8mb4',
            'group' => null,
        ];

        // 解析 DSN 格式: mysql:host=xxx;port=3306;dbname=xxx;sqlLogProxyGroup=xxx
        $parts = explode(';', $dsn);

        foreach ($parts as $part) {
            $keyValue = explode('=', $part, 2);
            if (count($keyValue) !== 2) {
                continue;
            }

            $key = strtolower(trim($keyValue[0]));
            $value = trim($keyValue[1]);

            switch ($key) {
                case 'host':
                case 'hostname':
                    $result['host'] = $value;
                    break;

                case 'port':
                    $result['port'] = (int) $value;
                    break;

                case 'dbname':
                case 'database':
                    $result['database'] = $value;
                    break;

                case 'user':
                case 'username':
                    $result['user'] = $value;
                    break;

                case 'password':
                case 'pass':
                    $result['password'] = $value;
                    break;

                case 'charset':
                    $result['charset'] = $value;
                    break;

                case 'sqllogproxygroup':
                case 'group':
                    $result['group'] = $value;
                    break;
            }
        }

        return $result;
    }

    public static function getGroup(string $dsn): ?string
    {
        $parsed = self::parse($dsn);
        return $parsed['group'];
    }

    public static function getHost(string $dsn): ?string
    {
        $parsed = self::parse($dsn);
        return $parsed['host'];
    }

    public static function getPort(string $dsn): int
    {
        $parsed = self::parse($dsn);
        return $parsed['port'];
    }

    public static function getDatabase(string $dsn): ?string
    {
        $parsed = self::parse($dsn);
        return $parsed['database'];
    }

    public static function extractParams(string $dsn, array $paramKeys): array
    {
        $parsed = self::parse($dsn);
        $result = [];

        foreach ($paramKeys as $key) {
            $lowerKey = strtolower($key);
            if (isset($parsed[$lowerKey])) {
                $result[$key] = $parsed[$lowerKey];
            }
        }

        return $result;
    }
}
