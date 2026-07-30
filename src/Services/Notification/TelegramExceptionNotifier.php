<?php

namespace Chencongbao\Foundation\Services\Notification;

use Throwable;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Chencongbao\Foundation\Contracts\ExceptionNotifier;
use Chencongbao\Foundation\Jobs\SendTelegramNotification;

final class TelegramExceptionNotifier implements ExceptionNotifier
{
    private TelegramNotificationSender $sender;
    private Dispatcher $dispatcher;
    private array $config;
    private ?CacheRepository $cache;

    public function __construct(
        TelegramNotificationSender $sender,
        Dispatcher $dispatcher,
        array $config,
        ?CacheRepository $cache = null
    )
    {
        $this->sender = $sender;
        $this->dispatcher = $dispatcher;
        $this->config = $config;
        $this->cache = $cache;
    }

    public function notify(string $module, Throwable $exception, array $context = []): bool
    {
        if (!$this->sender->configured()) {
            return false;
        }

        $cacheKey = $this->reserveException($module, $exception, $context);
        if ($cacheKey === false) {
            return true;
        }

        $sent = $this->send($this->exceptionMessage($module, $exception, $context));
        if (!$sent && is_string($cacheKey)) {
            try {
                $this->cache?->forget($cacheKey);
            } catch (Throwable) {
                // 缓存异常不能影响业务异常处理流程。
            }
        }

        return $sent;
    }

    /**
     * @return string|false|null 缓存键、重复异常、未启用或缓存不可用
     */
    private function reserveException(string $module, Throwable $exception, array $context)
    {
        if ($this->deduplicationExcluded($exception)) {
            return null;
        }

        $seconds = max(0, (int) ($this->config['deduplicate_seconds'] ?? 300));
        if ($seconds === 0 || $this->cache === null) {
            return null;
        }

        $fingerprintParts = [
            $module,
            get_class($exception),
            $exception->getMessage(),
        ];
        $contextFingerprint = $this->contextFingerprint($context);
        if ($contextFingerprint !== null) {
            $fingerprintParts[] = $contextFingerprint;
        }

        $fingerprint = hash('sha256', implode("\0", $fingerprintParts));
        $cacheKey = 'foundation:telegram:exception:'.$fingerprint;

        try {
            return $this->cache->add($cacheKey, true, $seconds) ? $cacheKey : false;
        } catch (Throwable) {
            return null;
        }
    }

    private function deduplicationExcluded(Throwable $exception): bool
    {
        foreach ((array) ($this->config['deduplicate_exclude_exceptions'] ?? []) as $class) {
            $class = trim((string) $class);
            if ($class !== '' && is_a($exception, $class)) {
                return true;
            }
        }

        return false;
    }

    private function contextFingerprint(array $context): ?string
    {
        $values = [];
        foreach ((array) ($this->config['deduplicate_context_keys'] ?? []) as $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $values[$key] = Arr::has($context, $key)
                ? Arr::get($context, $key)
                : ['__foundation_missing__' => true];
        }
        if ($values === []) {
            return null;
        }

        $encoded = json_encode(
            $values,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $encoded === false ? serialize($values) : $encoded;
    }

    private function send(string $message): bool
    {
        if (!$this->sender->configured()) {
            return false;
        }

        if (!(bool) ($this->config['queue']['enabled'] ?? true)) {
            return $this->sender->send($message);
        }

        try {
            $queue = (array) ($this->config['queue'] ?? []);
            $job = new SendTelegramNotification(
                $message,
                (int) ($queue['tries'] ?? 3),
                (int) ($queue['timeout_seconds'] ?? 30),
                (int) ($queue['backoff_seconds'] ?? 5)
            );
            $connection = trim((string) ($queue['connection'] ?? ''));
            if ($connection !== '') {
                $job->onConnection($connection);
            }
            $queueName = trim((string) ($queue['name'] ?? 'notice'));
            $job->onQueue($queueName !== '' ? $queueName : 'notice');
            $this->dispatcher->dispatch($job);

            return true;
        } catch (Throwable $exception) {
            $this->sender->reportFailure('Telegram 通知任务投递队列失败', [
                'exception' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_code' => $exception->getCode(),
                'queue' => (string) ($queue['name'] ?? 'notice'),
                'connection' => (string) ($queue['connection'] ?? ''),
                'message_hash' => hash('sha256', $message),
            ]);

            return false;
        }
    }

    private function exceptionMessage(string $module, Throwable $exception, array $context): string
    {
        $application = (string) ($this->config['application'] ?? 'Laravel');
        $payload = [
            '标题' => '['.$application.'] Foundation 异常通知',
            '运行环境' => (string) ($this->config['environment'] ?? '未知'),
            '功能模块' => $module,
            '异常类型' => get_class($exception),
            '异常消息' => $exception->getMessage(),
            '错误代码' => $exception->getCode(),
            '发生时间' => date(DATE_ATOM),
            '上下文' => $context,
        ];

        $json = $this->encodeMessage($payload);
        if (strlen($json) <= 3900) {
            return $json;
        }

        $contextJson = json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        $payload['标题'] = $this->truncateText('['.$application.'] Foundation 异常通知', 256);
        $payload['运行环境'] = $this->truncateText((string) ($this->config['environment'] ?? '未知'), 128);
        $payload['功能模块'] = $this->truncateText($module, 128);
        $payload['异常类型'] = $this->truncateText(get_class($exception), 256);
        $payload['异常消息'] = $this->truncateText($exception->getMessage(), 1000);
        $payload['上下文'] = [
            'truncated' => true,
            'sha256' => hash('sha256', $contextJson === false ? serialize($context) : $contextJson),
        ];

        return $this->encodeMessage($payload);
    }

    private function encodeMessage(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $json === false ? '{}' : $json;
    }

    private function truncateText(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $value = substr($value, 0, max(0, $maxBytes - 3));
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }

        return $value.'...';
    }
}
