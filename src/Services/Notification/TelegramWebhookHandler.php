<?php

namespace Chencongbao\Foundation\Services\Notification;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Chencongbao\Foundation\DTOs\TelegramWebhookUpdate;

/**
 * 处理 Telegram Webhook 的解析、鉴权、去重和统一响应。
 */
final class TelegramWebhookHandler
{
    private CacheRepository $cache;
    private array $config;
    private ?string $secretTokenOverride = null;

    public function __construct(CacheRepository $cache, array $config = [])
    {
        $this->cache = $cache;
        $this->config = $config;
    }

    /**
     * 返回使用动态 Secret Token 的新实例。
     */
    public function withSecretToken(string $secretToken): self
    {
        $secretToken = trim($secretToken);
        if ($secretToken === '') {
            throw new \InvalidArgumentException('Telegram Webhook Secret Token 不能为空。');
        }

        $handler = clone $this;
        $handler->secretTokenOverride = $secretToken;

        return $handler;
    }

    /**
     * @param callable(TelegramWebhookUpdate): mixed $handler
     * @param null|callable(Throwable, TelegramWebhookUpdate): mixed $onException
     */
    public function handle(
        Request $request,
        callable $handler,
        ?callable $onException = null
    ): Response {
        if (!$this->validSecretToken($request)) {
            return new Response('forbidden', 403);
        }

        $raw = $request->getContent();
        $payload = json_decode($raw, true);
        if (!is_array($payload) || $payload === []) {
            return $this->ok();
        }

        $update = new TelegramWebhookUpdate($payload, $raw);
        if ($this->duplicate($update)) {
            return $this->ok();
        }

        try {
            $handler($update);
        } catch (Throwable $exception) {
            if ($onException !== null) {
                try {
                    $onException($exception, $update);
                } catch (Throwable) {
                    // 异常回调失败也必须及时向 Telegram 返回 200，避免重复推送。
                }
            }
        }

        return $this->ok();
    }

    public function sanitizeError(string $message, string $botToken = ''): string
    {
        $botToken = trim($botToken);
        if ($botToken !== '') {
            $message = str_replace($botToken, $this->maskToken($botToken), $message);
        }

        $message = preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot******', $message) ?? $message;
        $message = preg_replace('/\d{6,}:[A-Za-z0-9_-]{20,}/', '******', $message) ?? $message;

        return function_exists('mb_substr')
            ? mb_substr($message, 0, 500)
            : substr($message, 0, 500);
    }

    public function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }
        if (strlen($token) <= 10) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 6).str_repeat('*', 8).substr($token, -4);
    }

    private function validSecretToken(Request $request): bool
    {
        $expected = $this->secretTokenOverride;
        if ($expected === null) {
            $expected = trim((string) ($this->config['secret_token'] ?? ''));
        }
        if ($expected === '') {
            return true;
        }

        $actual = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        return $actual !== '' && hash_equals($expected, $actual);
    }

    private function duplicate(TelegramWebhookUpdate $update): bool
    {
        $updateId = $update->updateId();
        $seconds = max(0, (int) ($this->config['deduplicate_seconds'] ?? 600));
        if ($updateId === null || $seconds === 0) {
            return false;
        }

        $prefix = (string) ($this->config['cache_prefix'] ?? 'foundation:telegram:webhook:');
        try {
            return !$this->cache->add($prefix.$updateId, true, $seconds);
        } catch (Throwable) {
            // 缓存不可用时放行，避免 Webhook 整体不可用。
            return false;
        }
    }

    private function ok(): Response
    {
        return new Response('ok', 200);
    }
}
