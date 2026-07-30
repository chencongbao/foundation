<?php

namespace Chencongbao\Foundation\Services\Logging;

use Throwable;
use InvalidArgumentException;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Chencongbao\Foundation\Contracts\ExceptionNotifier;

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
    private array $config;
    /** @var array<string, LoggerInterface> */
    private array $moduleLoggers = [];

    public function __construct(
        LogManager $logs,
        ExceptionNotifier $notifier,
        array $config
    )
    {
        $this->logs = $logs;
        $this->notifier = $notifier;
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
        $this->log($module, $level, $message, $context);
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
        $safeContext = $this->sanitize($context);

        try {
            $this->writeExceptionLog($module, $exception, $safeContext);
        } catch (Throwable) {
            // 本地日志系统故障不能阻断异常通知，也不能覆盖原始业务异常。
        }

        $this->notifier->notify($module, $exception, $safeContext);
    }

    private function writeExceptionLog(string $module, Throwable $exception, array $context): void
    {
        $settings = array_replace([
            'channel' => 'stack',
            'driver' => 'single',
            'path' => null,
            'level' => LogLevel::ERROR,
        ], (array) ($this->config['exception'] ?? []));

        $cacheKey = $this->loggerCacheKey('exception', $settings);
        $logger = $this->moduleLoggers[$cacheKey] ??= $this->moduleLogger($settings);
        $logger->log(
            LogLevel::ERROR,
            '['.$module.'] '.$exception->getMessage(),
            array_merge($context, $this->exceptionDetails($exception))
        );
    }

    private function exceptionDetails(Throwable $exception): array
    {
        $previous = [];
        $current = $exception->getPrevious();
        $depth = 0;

        while ($current !== null && $depth < 10) {
            $previous[] = [
                'exception' => get_class($current),
                'message' => $current->getMessage(),
                'code' => $current->getCode(),
                'file' => $current->getFile(),
                'line' => $current->getLine(),
                'trace' => $this->safeTrace($current),
            ];
            $current = $current->getPrevious();
            $depth++;
        }

        return [
            'exception' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->safeTrace($exception),
            'previous' => $previous,
            'runtime' => [
                'sapi' => PHP_SAPI,
                'pid' => getmypid(),
                'memory_usage' => memory_get_usage(true),
                'memory_peak_usage' => memory_get_peak_usage(true),
            ],
        ];
    }

    private function safeTrace(Throwable $exception): array
    {
        return array_map(static function (array $frame): array {
            return [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
                'function' => $frame['function'] ?? null,
            ];
        }, $exception->getTrace());
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
