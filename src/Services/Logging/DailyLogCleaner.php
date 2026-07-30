<?php

namespace Chencongbao\Foundation\Services\Logging;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * 清理日志根目录下过期的 YYYY-MM-DD 日期目录。
 *
 * 该类只处理名称严格符合日期格式的直接子目录，不会触碰普通文件、非日期目录或符号链接。
 */
final class DailyLogCleaner
{
    private const LOCK_FILE = '.foundation-log-retention.lock';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return int 成功删除的过期日期目录数量
     */
    public function cleanup(): int
    {
        $days = max(0, (int) ($this->config['days'] ?? 0));
        $configuredPath = trim((string) ($this->config['path'] ?? ''));
        if ($days === 0 || $configuredPath === '') {
            return 0;
        }

        try {
            return $this->cleanupSafely($configuredPath, $days);
        } catch (Throwable) {
            // 日志清理失败不能阻断 Web 请求、命令或 Queue Worker 启动。
            return 0;
        }
    }

    private function cleanupSafely(string $configuredPath, int $days): int
    {
        $root = realpath($configuredPath);
        if (
            $root === false
            || !is_dir($root)
            || is_link($configuredPath)
            || $this->isUnsafeRoot($root)
        ) {
            return 0;
        }

        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $timezone);
        $runKey = $today->format('Y-m-d').'|'.$days;
        $lock = @fopen($root.DIRECTORY_SEPARATOR.self::LOCK_FILE, 'c+');
        if (!is_resource($lock)) {
            return 0;
        }

        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) {
                return 0;
            }

            rewind($lock);
            if (trim((string) stream_get_contents($lock)) === $runKey) {
                return 0;
            }

            $cutoff = $today->modify('-'.($days - 1).' days');
            $deleted = 0;
            foreach ((array) scandir($root) as $entry) {
                if (!$this->isExpiredDateDirectory($root, (string) $entry, $cutoff, $timezone)) {
                    continue;
                }

                $directory = $root.DIRECTORY_SEPARATOR.$entry;
                if ($this->deleteDirectory($directory)) {
                    $deleted++;
                }
            }

            rewind($lock);
            @ftruncate($lock, 0);
            @fwrite($lock, $runKey);
            @fflush($lock);

            return $deleted;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function isExpiredDateDirectory(
        string $root,
        string $entry,
        DateTimeImmutable $cutoff,
        DateTimeZone $timezone
    ): bool {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $entry) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $entry, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $entry
            || $date >= $cutoff
        ) {
            return false;
        }

        $directory = $root.DIRECTORY_SEPARATOR.$entry;

        return is_dir($directory) && !is_link($directory);
    }

    private function deleteDirectory(string $directory): bool
    {
        if (!is_dir($directory) || is_link($directory)) {
            return false;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return false;
        }

        $success = true;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path) || !is_dir($path)) {
                $success = @unlink($path) && $success;
                continue;
            }

            $success = $this->deleteDirectory($path) && $success;
        }

        return @rmdir($directory) && $success;
    }

    private function isUnsafeRoot(string $root): bool
    {
        $normalized = rtrim(str_replace('\\', '/', $root), '/');

        return $normalized === ''
            || $normalized === '/'
            || preg_match('/^[A-Za-z]:$/D', $normalized) === 1;
    }
}
