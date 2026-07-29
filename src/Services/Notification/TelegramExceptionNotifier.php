<?php

namespace Chencongbao\Foundation\Services\Notification;

use Throwable;
use GuzzleHttp\ClientInterface;
use Chencongbao\Foundation\Contracts\ExceptionNotifier;

final class TelegramExceptionNotifier implements ExceptionNotifier
{
    private ClientInterface $httpClient;
    private array $config;

    public function __construct(ClientInterface $httpClient, array $config)
    {
        $this->httpClient = $httpClient;
        $this->config = $config;
    }

    public function notify(string $module, Throwable $exception, array $context = []): bool
    {
        if (!$this->configured()) {
            return false;
        }

        $success = true;
        foreach ((array) $this->config['chat_ids'] as $chatId) {
            try {
                $params = [
                    'chat_id' => (string) $chatId,
                    'text' => $this->message($module, $exception, $context),
                    'disable_web_page_preview' => true,
                ];
                $threadId = $this->config['message_thread_id'] ?? null;
                if ($threadId !== null && $threadId !== '') {
                    $params['message_thread_id'] = (int) $threadId;
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
                }
            } catch (Throwable) {
                $success = false;
            }
        }

        return $success;
    }

    private function configured(): bool
    {
        return ($this->config['enabled'] ?? false) === true
            && trim((string) ($this->config['bot_token'] ?? '')) !== ''
            && (array) ($this->config['chat_ids'] ?? []) !== [];
    }

    private function endpoint(): string
    {
        return 'https://api.telegram.org/bot'.trim((string) $this->config['bot_token']).'/sendMessage';
    }

    private function message(string $module, Throwable $exception, array $context): string
    {
        $lines = [
            '['.(string) ($this->config['application'] ?? 'Laravel').'] Foundation exception',
            'Environment: '.(string) ($this->config['environment'] ?? 'unknown'),
            'Module: '.$module,
            'Exception: '.get_class($exception),
            'Message: '.$exception->getMessage(),
            'Code: '.(string) $exception->getCode(),
            'Time: '.date(DATE_ATOM),
        ];
        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $lines[] = 'Context: '.($json === false ? '{}' : $json);
        }

        return substr(implode("\n", $lines), 0, 3900);
    }
}
