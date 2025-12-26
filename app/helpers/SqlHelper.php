<?php

declare(strict_types=1);

namespace App\Helpers;

class SqlHelper
{
    private static array $keywords = [
        'SELECT', 'FROM', 'WHERE', 'INSERT', 'INTO', 'VALUES',
        'UPDATE', 'SET', 'DELETE', 'CREATE', 'DROP', 'ALTER',
        'TABLE', 'INDEX', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER',
        'ON', 'AND', 'OR', 'NOT', 'NULL', 'IS', 'LIKE', 'IN',
        'BETWEEN', 'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT', 'OFFSET',
        'UNION', 'DISTINCT', 'AS', 'BY', 'DESC', 'ASC', 'WITH',
        'REPLACE', 'TRUNCATE', 'SHOW', 'DESCRIBE', 'EXPLAIN',
        'BEGIN', 'COMMIT', 'ROLLBACK', 'START TRANSACTION',
    ];

    /**
     * SQL语法高亮
     */
    public static function highlightSql(string $sql): string
    {
        $highlighted = $sql;

        // 高亮关键字
        foreach (self::$keywords as $keyword) {
            $pattern = '/\b(' . $keyword . ')\b/i';
            $replacement = "\033[1;35m$1\033[0m"; // 紫色加粗
            $highlighted = preg_replace($pattern, $replacement, $highlighted);
        }

        // 高亮字符串
        $highlighted = preg_replace(
            '/\'([^\']*)\'/',
            "\033[32m'$1'\033[0m", // 绿色
            $highlighted
        );

        // 高亮数字
        $highlighted = preg_replace(
            '/\b(\d+)\b/',
            "\033[33m$1\033[0m", // 黄色
            $highlighted
        );

        return $highlighted;
    }

    /**
     * 格式化SQL（简单版）
     */
    public static function formatSql(string $sql): string
    {
        $sql = Query::normalizeSql($sql);

        // 在关键字后添加换行
        $keywords = ['SELECT', 'FROM', 'WHERE', 'INSERT', 'INTO', 'VALUES',
                     'UPDATE', 'SET', 'DELETE', 'LEFT JOIN', 'RIGHT JOIN',
                     'INNER JOIN', 'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT'];

        foreach (array_reverse($keywords) as $keyword) {
            $pattern = '/\b(' . $keyword . ')\b/i';
            $sql = preg_replace($pattern, "\n$1", $sql);
        }

        // 移除开头的换行
        return trim($sql);
    }

    /**
     * 获取SQL类型
     */
    public static function getSqlType(string $sql): string
    {
        $sql = strtoupper(trim($sql));

        if (str_starts_with($sql, 'SELECT')) {
            return 'SELECT';
        } elseif (str_starts_with($sql, 'INSERT')) {
            return 'INSERT';
        } elseif (str_starts_with($sql, 'UPDATE')) {
            return 'UPDATE';
        } elseif (str_starts_with($sql, 'DELETE')) {
            return 'DELETE';
        } elseif (str_starts_with($sql, 'CREATE')) {
            return 'CREATE';
        } elseif (str_starts_with($sql, 'DROP')) {
            return 'DROP';
        } elseif (str_starts_with($sql, 'ALTER')) {
            return 'ALTER';
        } elseif (str_starts_with($sql, 'SHOW')) {
            return 'SHOW';
        } elseif (str_starts_with($sql, 'DESCRIBE') || str_starts_with($sql, 'DESC')) {
            return 'DESCRIBE';
        } elseif (str_starts_with($sql, 'BEGIN') || str_starts_with($sql, 'START TRANSACTION')) {
            return 'BEGIN';
        } elseif (str_starts_with($sql, 'COMMIT')) {
            return 'COMMIT';
        } elseif (str_starts_with($sql, 'ROLLBACK')) {
            return 'ROLLBACK';
        }

        return 'OTHER';
    }

    /**
     * 提取表名
     */
    public static function extractTableNames(string $sql): array
    {
        $tables = [];
        $sql = strtoupper($sql);

        // FROM 子句
        if (preg_match_all('/FROM\s+(\w+)/', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // JOIN 子句
        if (preg_match_all('/(?:JOIN)\s+(\w+)/', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // INSERT INTO
        if (preg_match('/INSERT\s+INTO\s+(\w+)/', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // UPDATE
        if (preg_match('/UPDATE\s+(\w+)/', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // DELETE FROM
        if (preg_match('/DELETE\s+FROM\s+(\w+)/', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        return array_unique($tables);
    }
}
