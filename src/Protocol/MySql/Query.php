<?php

declare(strict_types=1);

namespace Src\Protocol\MySql;

class Query
{
    public static function parseQueryPacket(Packet $packet): string
    {
        if ($packet->getCommand() !== Command::COM_QUERY) {
            throw new \InvalidArgumentException('不是QUERY命令包');
        }
        return $packet->getPayloadWithoutCommand();
    }

    public static function isTransactionCommand(string $sql): bool
    {
        $sql = strtoupper(trim($sql));
        return str_starts_with($sql, 'BEGIN') ||
               str_starts_with($sql, 'START TRANSACTION') ||
               str_starts_with($sql, 'COMMIT') ||
               str_starts_with($sql, 'ROLLBACK') ||
               str_starts_with($sql, 'XA START') ||
               str_starts_with($sql, 'XA END') ||
               str_starts_with($sql, 'XA PREPARE') ||
               str_starts_with($sql, 'XA COMMIT') ||
               str_starts_with($sql, 'XA ROLLBACK');
    }

    public static function isTransactionStart(string $sql): bool
    {
        $sql = strtoupper(trim($sql));
        return str_starts_with($sql, 'BEGIN') || str_starts_with($sql, 'START TRANSACTION');
    }

    public static function isTransactionCommit(string $sql): bool
    {
        $sql = strtoupper(trim($sql));
        return str_starts_with($sql, 'COMMIT');
    }

    public static function isTransactionRollback(string $sql): bool
    {
        $sql = strtoupper(trim($sql));
        return str_starts_with($sql, 'ROLLBACK');
    }

    public static function getTransactionType(string $sql): ?string
    {
        $sql = strtoupper(trim($sql));
        if (self::isTransactionStart($sql)) {
            return 'start';
        } elseif (self::isTransactionCommit($sql)) {
            return 'commit';
        } elseif (self::isTransactionRollback($sql)) {
            return 'rollback';
        }
        return null;
    }

    public static function normalizeSql(string $sql): string
    {
        // 移除注释
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/#.*$/m', '', $sql);
        $sql = preg_replace('/\/\*[\s\S]*?\*\//', '', $sql);

        // 去除多余空白
        $sql = preg_replace('/\s+/', ' ', $sql);
        $sql = trim($sql);

        return $sql;
    }

    public static function extractDatabaseFromSql(string $sql): ?string
    {
        $sql = strtoupper(trim($sql));
        if (preg_match('/^(USE|USE\s+\w+)/', $sql, $matches)) {
            $sqlLower = strtolower(trim($sql));
            if (preg_match('/^use\s+(\w+)/', $sqlLower, $dbMatches)) {
                return $dbMatches[1];
            }
        }
        return null;
    }
}
