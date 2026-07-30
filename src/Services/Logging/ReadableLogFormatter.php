<?php

namespace Chencongbao\Foundation\Services\Logging;

use DateTimeZone;
use DateTimeImmutable;

/**
 * 将 Foundation 日志组织为适合直接阅读和 tail -f 的多行区块。
 */
final class ReadableLogFormatter
{
    public static function format(string $title, array $fields, array $context = []): string
    {
        $lines = [
            '',
            '==================== '.$title.' ====================',
            '发生时间：'.self::beijingTime(),
        ];

        foreach ($fields as $label => $value) {
            $lines[] = self::field((string) $label, $value);
        }

        $lines[] = '------------------------- 上下文 -------------------------';
        $lines[] = self::prettyJson($context);
        $lines[] = '============================================================';
        $lines[] = '';

        return implode("\n", $lines);
    }

    public static function beijingTime(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
            ->format('Y-m-d H:i:s');
    }

    public static function prettyJson(array $context): string
    {
        $json = json_encode(
            $context,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $json === false ? '{}' : $json;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return number_format($bytes / 1024 / 1024, 2).' MB';
    }

    private static function field(string $label, mixed $value): string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null || $value === '') {
            $value = '-';
        } elseif (!is_scalar($value)) {
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $value = $encoded === false ? '-' : $encoded;
        }

        return $label.'：'.str_replace("\n", "\n    ", (string) $value);
    }
}
