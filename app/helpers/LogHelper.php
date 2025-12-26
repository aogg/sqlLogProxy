<?php

declare(strict_types=1);

namespace App\Helpers;

class LogHelper
{
    private static string $basePath;
    private static string $dateFormat = 'Ym/d/H-i';

    public static function init(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
    }

    public static function generatePath(?string $group = null, ?string $transactionId = null): string
    {
        $datePath = date(self::$dateFormat);
        $path = self::$basePath . '/' . $datePath;

        if ($group !== null) {
            $path .= '/' . $group;
        }

        if ($transactionId !== null) {
            $path .= '/' . $transactionId;
        }

        return $path;
    }

    public static function getLogFile(?string $group = null, ?string $transactionId = null): string
    {
        $path = self::generatePath($group, $transactionId);
        return $path . '/sql.log';
    }

    public static function ensureDirectory(?string $group = null, ?string $transactionId = null): void
    {
        $path = self::generatePath($group, $transactionId);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    public static function formatLogEntry(
        string $clientInfo,
        string $sql,
        int $elapsedMs = 0,
        int $affectedRows = 0
    ): string {
        $timestamp = date('Y-m-d H:i:s.v');
        return sprintf(
            "[%s] [%s] [耗时:%dms] [影响行数:%d]\n%s\n\n",
            $timestamp,
            $clientInfo,
            $elapsedMs,
            $affectedRows,
            $sql
        );
    }

    public static function writeLog(
        string $clientInfo,
        string $sql,
        ?string $group = null,
        ?string $transactionId = null,
        int $elapsedMs = 0,
        int $affectedRows = 0,
        bool $highlight = false
    ): void {
        self::ensureDirectory($group, $transactionId);
        $logFile = self::getLogFile($group, $transactionId);

        $sqlContent = $sql;
        if ($highlight) {
            $sqlContent = SqlHelper::highlightSql($sql);
        }

        $logEntry = self::formatLogEntry($clientInfo, $sqlContent, $elapsedMs, $affectedRows);

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
