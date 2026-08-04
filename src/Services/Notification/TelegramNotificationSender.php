<?php

namespace Chencongbao\Foundation\Services\Notification;

use Chencongbao\Foundation\Services\Logging\ReadableLogFormatter;
use Throwable;
use Illuminate\Log\LogManager;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

final class TelegramNotificationSender
{
    private ClientInterface $httpClient;
    private array $config;
    private ?LogManager $logs;
    private ?LoggerInterface $activityLogger = null;
    private ?string $activityLoggerDate = null;
    private ?LoggerInterface $failureLogger = null;
    private ?string $failureLoggerDate = null;

    public function __construct(
        ClientInterface $httpClient,
        array $config,
        ?LogManager $logs = null
    ) {
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

    /**
     * 调用不依赖 Chat ID 的 Telegram Bot API。
     *
     * @return mixed Telegram 响应中的 result；失败时返回 null
     */
    public function call(string $method, array $params = []): mixed
    {
        if (trim((string) ($this->config['bot_token'] ?? '')) === '') {
            $this->reportFailure('Telegram Bot Token 未配置', [
                'method' => $method,
            ]);

            return null;
        }

        $startedAt = microtime(true);
        try {
            $timeout = max(0.5, (float) ($this->config['timeout_seconds'] ?? 3));
            $response = $this->httpClient->request('POST', $this->endpoint($method), [
                'connect_timeout' => min(1.0, $timeout),
                'timeout' => $timeout,
                'http_errors' => false,
                'form_params' => $params,
            ]);
            $payload = json_decode((string) $response->getBody(), true);
            if (
                $response->getStatusCode() >= 200
                && $response->getStatusCode() < 300
                && is_array($payload)
                && ($payload['ok'] ?? false) === true
            ) {
                $result = $payload['result'] ?? null;
                $this->reportActivity('Telegram Bot API 调用成功', [
                    'method' => $method,
                    'http_status' => $response->getStatusCode(),
                    'duration_ms' => $this->durationMilliseconds($startedAt),
                    'result_type' => get_debug_type($result),
                    'result_keys' => is_array($result) ? array_keys($result) : [],
                ]);

                return $result;
            }

            $this->reportFailure('Telegram Bot API 调用失败', [
                'method' => $method,
                'http_status' => $response->getStatusCode(),
                'duration_ms' => $this->durationMilliseconds($startedAt),
                'telegram_error_code' => is_array($payload) ? ($payload['error_code'] ?? null) : null,
                'telegram_description' => is_array($payload) ? ($payload['description'] ?? null) : null,
                'response_is_json' => is_array($payload),
                'parameter_keys' => array_keys($params),
            ]);
        } catch (Throwable $exception) {
            $this->reportFailure('Telegram Bot API 请求发生异常', [
                'method' => $method,
                'exception' => get_class($exception),
                'exception_message' => $this->redact($exception->getMessage()),
                'exception_code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'duration_ms' => $this->durationMilliseconds($startedAt),
                'parameter_keys' => array_keys($params),
            ]);
        }

        return null;
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
            $startedAt = microtime(true);
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

                $response = $this->httpClient->request('POST', $this->endpoint('sendMessage'), [
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
                        'duration_ms' => $this->durationMilliseconds($startedAt),
                        'telegram_error_code' => is_array($payload) ? ($payload['error_code'] ?? null) : null,
                        'telegram_description' => is_array($payload) ? ($payload['description'] ?? null) : null,
                        'response_is_json' => is_array($payload),
                        'message_hash' => hash('sha256', $message),
                    ]);
                    continue;
                }

                $result = (array) ($payload['result'] ?? []);
                $this->reportActivity('Telegram 消息发送成功', [
                    'chat_id' => (string) $chatId,
                    'http_status' => $response->getStatusCode(),
                    'duration_ms' => $this->durationMilliseconds($startedAt),
                    'telegram_message_id' => $result['message_id'] ?? null,
                    'message_bytes' => strlen($message),
                    'message_hash' => hash('sha256', $message),
                    'reply_message_id' => $replyParameters['message_id'] ?? null,
                    'button_count' => $this->buttonCount($replyMarkup),
                ]);
            } catch (Throwable $exception) {
                $success = false;
                $this->reportFailure('Telegram 请求发生异常', [
                    'chat_id' => (string) $chatId,
                    'exception' => get_class($exception),
                    'exception_message' => $this->redact($exception->getMessage()),
                    'exception_code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'duration_ms' => $this->durationMilliseconds($startedAt),
                    'message_hash' => hash('sha256', $message),
                ]);
            }
        }

        return $success;
    }

    /**
     * 使用 URL 或 Telegram file_id 发送图片。
     */
    public function sendPhoto(string $photo, string $caption = '', array $options = []): bool
    {
        return $this->sendPhotoMessage($photo, $caption, $options, false);
    }

    /**
     * 使用 multipart/form-data 上传并发送本地图片。
     */
    public function sendPhotoFile(string $path, string $caption = '', array $options = []): bool
    {
        return $this->sendPhotoMessage($path, $caption, $options, true);
    }

    private function sendPhotoMessage(
        string $photo,
        string $caption,
        array $options,
        bool $upload
    ): bool {
        if (!$this->configured()) {
            $this->reportFailure('Telegram 图片通知配置不完整', [
                'telegram_enabled' => ($this->config['enabled'] ?? false) === true,
                'bot_token_configured' => trim((string) ($this->config['bot_token'] ?? '')) !== '',
                'chat_ids_count' => count((array) ($this->config['chat_ids'] ?? [])),
                'photo_source' => $upload ? 'upload' : 'remote_or_file_id',
            ]);

            return false;
        }

        $success = true;
        $replyParameters = (array) ($this->config['reply_parameters'] ?? []);
        $replyMarkup = (array) ($this->config['reply_markup'] ?? []);
        foreach ((array) $this->config['chat_ids'] as $chatId) {
            $startedAt = microtime(true);
            $stream = null;
            try {
                $params = [
                    'chat_id' => (string) $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ];
                foreach ($options as $key => $value) {
                    $params[$key] = $value;
                }
                if (isset($replyParameters['message_id'])) {
                    $params['reply_parameters'] = json_encode(
                        $replyParameters,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );
                }
                if (isset($replyMarkup['inline_keyboard'])) {
                    $params['reply_markup'] = json_encode(
                        $replyMarkup,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );
                }

                $requestOptions = [
                    'connect_timeout' => min(1.0, (float) $this->config['timeout_seconds']),
                    'timeout' => (float) $this->config['timeout_seconds'],
                    'http_errors' => false,
                ];
                if ($upload) {
                    $stream = @fopen($photo, 'rb');
                    if (!is_resource($stream)) {
                        throw new \RuntimeException('无法读取待上传的 Telegram 图片。');
                    }
                    $requestOptions['multipart'] = $this->multipartFields($params);
                    $requestOptions['multipart'][] = [
                        'name' => 'photo',
                        'contents' => $stream,
                        'filename' => basename($photo),
                    ];
                } else {
                    $params['photo'] = $photo;
                    $requestOptions['form_params'] = $params;
                }

                $response = $this->httpClient->request(
                    'POST',
                    $this->endpoint('sendPhoto'),
                    $requestOptions
                );
                $payload = json_decode((string) $response->getBody(), true);
                if (
                    $response->getStatusCode() < 200
                    || $response->getStatusCode() >= 300
                    || !is_array($payload)
                    || ($payload['ok'] ?? false) !== true
                ) {
                    $success = false;
                    $this->reportFailure('Telegram API 返回图片发送失败', [
                        'chat_id' => (string) $chatId,
                        'photo_source' => $upload ? 'upload' : 'remote_or_file_id',
                        'http_status' => $response->getStatusCode(),
                        'duration_ms' => $this->durationMilliseconds($startedAt),
                        'telegram_error_code' => is_array($payload) ? ($payload['error_code'] ?? null) : null,
                        'telegram_description' => is_array($payload) ? ($payload['description'] ?? null) : null,
                        'response_is_json' => is_array($payload),
                        'photo_reference_hash' => hash('sha256', $photo),
                        'caption_hash' => hash('sha256', $caption),
                    ]);
                    continue;
                }

                $result = (array) ($payload['result'] ?? []);
                $this->reportActivity('Telegram 图片发送成功', [
                    'chat_id' => (string) $chatId,
                    'photo_source' => $upload ? 'upload' : 'remote_or_file_id',
                    'http_status' => $response->getStatusCode(),
                    'duration_ms' => $this->durationMilliseconds($startedAt),
                    'telegram_message_id' => $result['message_id'] ?? null,
                    'photo_reference_hash' => hash('sha256', $photo),
                    'photo_bytes' => $upload ? (filesize($photo) ?: null) : null,
                    'caption_bytes' => strlen($caption),
                    'caption_hash' => hash('sha256', $caption),
                    'reply_message_id' => $replyParameters['message_id'] ?? null,
                    'button_count' => $this->buttonCount($replyMarkup),
                    'has_spoiler' => (bool) ($options['has_spoiler'] ?? false),
                ]);
            } catch (Throwable $exception) {
                $success = false;
                $this->reportFailure('Telegram 图片请求发生异常', [
                    'chat_id' => (string) $chatId,
                    'photo_source' => $upload ? 'upload' : 'remote_or_file_id',
                    'exception' => get_class($exception),
                    'exception_message' => $this->redact($exception->getMessage()),
                    'exception_code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'duration_ms' => $this->durationMilliseconds($startedAt),
                    'photo_reference_hash' => hash('sha256', $photo),
                    'caption_hash' => hash('sha256', $caption),
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
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

    /**
     * 记录 Telegram 成功操作；可单独关闭，不影响失败日志。
     */
    public function reportActivity(string $message, array $context = []): void
    {
        if (
            $this->logs === null
            || !(bool) ($this->config['activity_log']['enabled'] ?? false)
        ) {
            return;
        }

        try {
            $logger = $this->activityLogger();
            $safeContext = $this->sanitizeContext($context);
            $application = trim((string) ($this->config['application'] ?? '-'));
            $logger->info(ReadableLogFormatter::format('Telegram 操作日志', [
                '应用名称' => $application === '' ? '-' : $application,
                '操作说明' => $message,
            ], $safeContext), []);
        } catch (Throwable) {
            // 操作日志失败不能影响 Telegram API 调用结果。
        }
    }

    private function activityLogger(): LoggerInterface
    {
        $settings = array_replace(
            [
                'channel' => 'stack',
                'driver' => 'single',
                'path' => null,
                'level' => 'info',
            ],
            (array) ($this->config['activity_log'] ?? [])
        );
        $date = substr(ReadableLogFormatter::beijingTime(), 0, 10);

        if ($this->activityLogger !== null && $this->activityLoggerDate === $date) {
            return $this->activityLogger;
        }

        $this->activityLogger = $this->buildLogger($settings, $date);
        $this->activityLoggerDate = $date;

        return $this->activityLogger;
    }

    private function failureLogger(): LoggerInterface
    {
        $settings = array_replace(
            [
                'channel' => 'stack',
                'driver' => 'single',
                'path' => null,
                'level' => 'error',
            ],
            // 兼容短暂使用过的 log 配置名称。
            (array) ($this->config['log'] ?? []),
            (array) ($this->config['failure_log'] ?? [])
        );
        $date = substr(ReadableLogFormatter::beijingTime(), 0, 10);

        if ($this->failureLogger !== null && $this->failureLoggerDate === $date) {
            return $this->failureLogger;
        }

        $this->failureLogger = $this->buildLogger($settings, $date);
        $this->failureLoggerDate = $date;

        return $this->failureLogger;
    }

    private function buildLogger(array $settings, string $date): LoggerInterface
    {
        $path = trim((string) ($settings['path'] ?? ''));

        return $path !== ''
            ? $this->logs->build([
                'driver' => (string) $settings['driver'],
                'path' => str_replace('{date}', $date, $path),
                'level' => (string) $settings['level'],
            ])
            : $this->logs->channel((string) $settings['channel']);
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

    private function buttonCount(array $replyMarkup): int
    {
        $count = 0;
        foreach ((array) ($replyMarkup['inline_keyboard'] ?? []) as $row) {
            $count += is_array($row) ? count($row) : 0;
        }

        return $count;
    }

    private function multipartFields(array $params): array
    {
        $fields = [];
        foreach ($params as $name => $value) {
            $fields[] = [
                'name' => (string) $name,
                'contents' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            ];
        }

        return $fields;
    }

    private function durationMilliseconds(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot'
            .trim((string) $this->config['bot_token'])
            .'/'
            .$method;
    }
}
