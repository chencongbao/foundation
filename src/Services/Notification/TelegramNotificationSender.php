<?php

namespace Chencongbao\Foundation\Services\Notification;

use Throwable;
use Illuminate\Log\LogManager;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Chencongbao\Foundation\Services\Logging\ReadableLogFormatter;

final class TelegramNotificationSender
{
    private ClientInterface $httpClient;
    private array $config;
    private ?LogManager $logs;
    private ?LoggerInterface $failureLogger = null;
    private ?string $failureLoggerDate = null;

    public function __construct(ClientInterface $httpClient, array $config, ?LogManager $logs = null)
    {
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->logs = $logs;
    }

    public function configured(): bool
    {
        return ($this->config['enabled'] ?? false) === true
            && trim((string) ($this->config['bot_token'] ?? '')) !== ''
            && (array) ($this->config['chat_ids'] ?? []) !== [];
    }

    public function send(string $message): bool
    {
        if (!$this->configured()) {
            $this->reportFailure('Telegram 通知配置不完整', [
                'telegram_enabled' => ($this->config['enabled'] ?? false) === true,
                'bot_token_configured' => trim((string) ($this->config['bot_token'] ?? '')) !== '',
                'chat_ids_count' => count((array) ($this->config['chat_ids'] ?? [])),
            ]);

            return false;
        }

        $success = true;
        foreach ((array) $this->config['chat_ids'] as $chatId) {
            try {
                $params = [
                    'chat_id' => (string) $chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ];
                $replyParameters = (array) ($this->config['reply_parameters'] ?? []);
                if (isset($replyParameters['message_id'])) {
                    $params['reply_parameters'] = json_encode(
                        $replyParameters,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );
                }
                $replyMarkup = (array) ($this->config['reply_markup'] ?? []);
                if (isset($replyMarkup['inline_keyboard'])) {
                    $params['reply_markup'] = json_encode(
                        $replyMarkup,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );
                }

                $response = $this->httpClient->request('POST', $this->endpoint(), [
                    'connect_timeout' => min(1.0, (float) $this->config['timeout_seconds']),
                    'timeout' => (float) $this->config['timeout_seconds'],
                    'http_errors' => false,
                    'form_params' => $params,
                ]);
                $payload = json_decode((string) $response->getBody(), true);
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || !is_array($payload) || ($payload['ok'] ?? false) !== true) {
                    $success = false;
                    $this->reportFailure('Telegram API 返回发送失败', [
                        'chat_id' => (string) $chatId,
                        'http_status' => $response->getStatusCode(),
                        'telegram_error_code' => is_array($payload) ? ($payload['error_code'] ?? null) : null,
                        'telegram_description' => is_array($payload) ? ($payload['description'] ?? null) : null,
                        'response_is_json' => is_array($payload),
                        'message_hash' => hash('sha256', $message),
                    ]);
                }
            } catch (Throwable $exception) {
                $success = false;
                $this->reportFailure('Telegram 请求发生异常', [
                    'chat_id' => (string) $chatId,
                    'exception' => get_class($exception),
                    'exception_message' => $this->redact($exception->getMessage()),
                    'exception_code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'message_hash' => hash('sha256', $message),
                ]);
            }
        }

        return $success;
    }

    /**
     * 记录通知链路失败；日志系统本身失败时静默返回，避免影响业务和队列重试。
     */
    public function reportFailure(string $message, array $context = []): void
    {
        if ($this->logs === null) {
            return;
        }

        try {
            $logger = $this->failureLogger();
            $safeContext = $this->sanitizeContext($context);
            $node = trim((string) ($safeContext['node'] ?? $this->config['application'] ?? '-'));
            $logger->error(ReadableLogFormatter::format('Telegram 通知失败', [
                '节点名称' => $node === '' ? '-' : $node,
                '错误说明' => $message,
            ], $safeContext), []);
        } catch (Throwable) {
            // 通知失败日志不能覆盖原始异常，也不能阻断队列的正常重试。
        }
    }

    private function failureLogger(): LoggerInterface
    {
        $settings = array_replace([
            'channel' => 'stack',
            'driver' => 'single',
            'path' => null,
            'level' => 'error',
        ], (array) ($this->config['failure_log'] ?? []));
        $path = trim((string) ($settings['path'] ?? ''));
        $date = date('Y-m-d');

        if ($this->failureLogger !== null && ($path === '' || $this->failureLoggerDate === $date)) {
            return $this->failureLogger;
        }

        $this->failureLogger = $path !== ''
            ? $this->logs->build([
                'driver' => (string) $settings['driver'],
                'path' => str_replace('{date}', $date, $path),
                'level' => (string) $settings['level'],
            ])
            : $this->logs->channel((string) $settings['channel']);
        $this->failureLoggerDate = $date;

        return $this->failureLogger;
    }

    private function redact(string $message): string
    {
        $token = trim((string) ($this->config['bot_token'] ?? ''));

        return $token === '' ? $message : str_replace($token, '[REDACTED]', $message);
    }

    private function sanitizeContext(array $context): array
    {
        foreach ($context as $key => $value) {
            $keyName = strtolower((string) $key);
            if (
                str_contains($keyName, 'token')
                || str_contains($keyName, 'secret')
                || str_contains($keyName, 'authorization')
            ) {
                $context[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $context[$key] = $this->sanitizeContext($value);
                continue;
            }
            if (is_string($value)) {
                $context[$key] = $this->redact($value);
            }
        }

        return $context;
    }

    private function endpoint(): string
    {
        return 'https://api.telegram.org/bot'.trim((string) $this->config['bot_token']).'/sendMessage';
    }
}
