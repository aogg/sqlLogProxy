<?php

declare(strict_types=1);

namespace App\Helpers;

class WildcardHelper
{
    /**
     * 通配符匹配
     * 支持 * 匹配任意字符
     */
    public static function match(string $pattern, string $text): bool
    {
        $pattern = self::normalizePattern($pattern);

        // 转义正则表达式特殊字符（除了*）
        $regex = str_replace(
            ['\\', '^', '$', '.', '+', '?', '[', ']', '(', ')', '|', '{', '}'],
            ['\\\\', '\\^', '\\$', '\\.', '\\+', '\\?', '\\[', '\\]', '\\(', '\\)', '\\|', '\\{', '\\}'],
            $pattern
        );

        // 将 * 替换为 .* (匹配任意字符)
        $regex = str_replace('*', '.*', $regex);

        // 添加开始和结束锚点
        $regex = '/^' . $regex . '$/i';

        return preg_match($regex, $text) === 1;
    }

    /**
     * 批量匹配
     * @param string[] $patterns
     */
    public static function matchAny(array $patterns, string $text): bool
    {
        foreach ($patterns as $pattern) {
            if (self::match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }

    private static function normalizePattern(string $pattern): string
    {
        return trim($pattern);
    }

    /**
     * 检查SQL是否匹配排除规则
     * @param string[] $excludePatterns
     */
    public static function shouldExclude(string $sql, array $excludePatterns): bool
    {
        return self::matchAny($excludePatterns, $sql);
    }
}
