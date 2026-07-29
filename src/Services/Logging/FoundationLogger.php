<?php

namespace Chencongbao\Foundation\Services\Logging;

use Throwable;
use InvalidArgumentException;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Chencongbao\Foundation\Contracts\ExceptionNotifier;
use Chencongbao\Foundation\Contracts\MessageNotifier;

final class FoundationLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 100,
        LogLevel::INFO => 200,
        LogLevel::NOTICE => 250,
        LogLevel::WARNING => 300,
        LogLevel::ERROR => 400,
        LogLevel::CRITICAL => 500,
        LogLevel::ALERT => 550,
        LogLevel::EMERGENCY => 600,
    ];

    private LogManager $logs;
    private ExceptionNotifier $notifier;
    private ?MessageNotifier $messageNotifier;
    private array $config;
    /** @var array<string, LoggerInterface> */
    private array $moduleLoggers = [];

    public function __construct(
        LogManager $logs,
        ExceptionNotifier $notifier,
        array $config,
        ?MessageNotifier $messageNotifier = null
    )
    {
        $this->logs = $logs;
        $this->notifier = $notifier;
        $this->messageNotifier = $messageNotifier;
        $this->config = $config;
    }

    public function debug(string $module, string $message, array $context = []): void
    {
        $this->log($module, LogLevel::DEBUG, $message, $context);
    }

    public function info(string $module, string $message, array $context = []): void
    {
        $this->log($module, LogLevel::INFO, $message, $context);
    }

    public function warning(string $module, string $message, array $context = []): void
    {
        $this->log($module, LogLevel::WARNING, $message, $context);
    }

    public function error(string $module, string $message, array $context = []): void
    {
        $this->log($module, LogLevel::ERROR, $message, $context);
    }

    public function log(string $module, string $level, string $message, array $context = []): void
    {
        $module = $this->moduleName($module);
        $settings = $this->moduleSettings($module);
        if (!$this->shouldLog($settings, $level)) {
            return;
        }

        $cacheKey = $this->loggerCacheKey($module, $settings);
        $logger = $this->moduleLoggers[$cacheKey] ??= $this->moduleLogger($settings);
        $logger->log($level, '['.$module.'] '.$message, $this->sanitize($context));
    }

    public function message(
        string $module,
        string $message,
        array $context = [],
        string $level = LogLevel::INFO
    ): void {
        $module = $this->moduleName($module);
        $settings = $this->moduleSettings($module);
        $loggingEnabled = ($settings['enabled'] ?? false) === true;
        $notificationEnabled = ($settings['notify'] ?? false) === true;
        if (!$loggingEnabled && !$notificationEnabled) {
            return;
        }

        $safeContext = $this->sanitize($context);
        if ($loggingEnabled) {
            $this->log($module, $level, $message, $safeContext);
        }

        if ($notificationEnabled && $this->messageNotifier !== null) {
            $this->messageNotifier->notifyMessage($module, $message, $safeContext);
        }
    }

    private function moduleLogger(array $settings): LoggerInterface
    {
        return isset($settings['path']) && trim((string) $settings['path']) !== ''
            ? $this->logs->build([
                'driver' => (string) ($settings['driver'] ?? 'single'),
                'path' => $this->datedPath((string) $settings['path']),
                'level' => (string) ($settings['level'] ?? LogLevel::DEBUG),
            ])
            : $this->logs->channel((string) ($settings['channel'] ?? 'stack'));
    }

    private function loggerCacheKey(string $module, array $settings): string
    {
        $path = (string) ($settings['path'] ?? '');

        return str_contains($path, '{date}') ? $module.'|'.date('Y-m-d') : $module;
    }

    private function datedPath(string $path): string
    {
        return str_replace('{date}', date('Y-m-d'), $path);
    }

    public function exception(string $module, Throwable $exception, array $context = []): void
    {
        $module = $this->moduleName($module);
        $settings = $this->moduleSettings($module);
        $loggingEnabled = ($settings['enabled'] ?? false) === true;
        $notificationEnabled = ($settings['notify'] ?? false) === true;
        if (!$loggingEnabled && !$notificationEnabled) {
            return;
        }

        $safeContext = $this->sanitize($context);
        if ($loggingEnabled) {
            $this->log($module, LogLevel::ERROR, $exception->getMessage(), $safeContext + [
                'exception' => get_class($exception),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }

        if ($notificationEnabled) {
            $this->notifier->notify($module, $exception, $safeContext);
        }
    }

    private function moduleSettings(string $module): array
    {
        return array_replace(
            (array) ($this->config['default'] ?? []),
            (array) ($this->config['modules'][$module] ?? [])
        );
    }

    private function shouldLog(array $settings, string $level): bool
    {
        if (!isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException("不支持的日志级别：{$level}");
        }
        if (!($settings['enabled'] ?? false)) {
            return false;
        }

        $minimum = strtolower((string) ($settings['level'] ?? LogLevel::DEBUG));

        return self::LEVELS[$level] >= (self::LEVELS[$minimum] ?? self::LEVELS[LogLevel::DEBUG]);
    }

    private function moduleName(string $module): string
    {
        $module = strtolower(trim($module));
        if ($module === '' || preg_match('/^[a-z0-9_.-]{1,64}$/', $module) !== 1) {
            throw new InvalidArgumentException('日志模块名格式错误。');
        }

        return $module;
    }

    private function sanitize(array $context): array
    {
        $sensitiveKeys = array_map('strtolower', (array) ($this->config['sensitive_keys'] ?? []));

        $sanitize = static function (array $values) use (&$sanitize, $sensitiveKeys): array {
            foreach ($values as $key => $value) {
                $keyName = strtolower((string) $key);
                foreach ($sensitiveKeys as $sensitiveKey) {
                    if ($sensitiveKey !== '' && str_contains($keyName, $sensitiveKey)) {
                        $values[$key] = '[REDACTED]';
                        continue 2;
                    }
                }
                if (is_array($value)) {
                    $values[$key] = $sanitize($value);
                }
            }

            return $values;
        };

        return $sanitize($context);
    }
}
