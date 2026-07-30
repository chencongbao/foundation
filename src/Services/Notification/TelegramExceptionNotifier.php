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

        $sent = $this->send($this->exceptionMessage($exception, $context));
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

    private function exceptionMessage(Throwable $exception, array $context): string
    {
        $node = trim((string) ($context['node'] ?? $this->config['application'] ?? 'Laravel'));
        unset($context['node']);

        $payload = [
            'node' => $node,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $this->relativePath($exception->getFile()),
            'line' => $exception->getLine(),
            'context' => $context,
        ];

        $json = $this->encodeMessage($payload);
        $message = $this->formatMessage($json, $node);
        if (strlen($message) <= 3900) {
            return $message;
        }

        $contextJson = json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        $payload['node'] = $this->truncateText($node, 128);
        $payload['exception'] = $this->truncateText(get_class($exception), 256);
        $payload['message'] = $this->truncateText($exception->getMessage(), 500);
        $payload['file'] = $this->truncateText($this->relativePath($exception->getFile()), 384);
        $payload['context'] = [
            'truncated' => true,
            'sha256' => hash('sha256', $contextJson === false ? serialize($context) : $contextJson),
        ];
        $message = $this->formatMessage($this->encodeMessage($payload), (string) $payload['node']);
        if (strlen($message) <= 3900) {
            return $message;
        }

        $payload['message'] = '[truncated sha256:'.hash('sha256', $exception->getMessage()).']';
        $payload['file'] = $this->truncateText((string) $payload['file'], 128);

        return $this->formatMessage($this->encodeMessage($payload), (string) $payload['node']);
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

    private function formatMessage(string $json, string $node): string
    {
        $configuredTitle = trim((string) ($this->config['exception_title'] ?? ''));
        $title = $configuredTitle === ''
            ? '['.$node.'] 系统异常'
            : str_replace('{node}', $node, $configuredTitle);
        $title = $this->truncateText($title, 128);

        return '<b>'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>'
            ."\n"
            .'<pre><code class="language-json">'
            .htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .'</code></pre>';
    }

    private function relativePath(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if (!function_exists('app')) {
            return $file;
        }

        try {
            $application = app();
            if (!is_object($application) || !method_exists($application, 'basePath')) {
                return $file;
            }
            $basePath = rtrim(str_replace('\\', '/', (string) $application->basePath()), '/').'/';
        } catch (Throwable) {
            return $file;
        }

        return str_starts_with($file, $basePath) ? substr($file, strlen($basePath)) : $file;
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
