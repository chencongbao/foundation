<?php

namespace Chencongbao\Foundation\Services\Notification;

use Throwable;
use GuzzleHttp\ClientInterface;

final class TelegramNotificationSender
{
    private ClientInterface $httpClient;
    private array $config;

    public function __construct(ClientInterface $httpClient, array $config)
    {
        $this->httpClient = $httpClient;
        $this->config = $config;
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
            return false;
        }

        $success = true;
        foreach ((array) $this->config['chat_ids'] as $chatId) {
            try {
                $params = [
                    'chat_id' => (string) $chatId,
                    'text' => $message,
                    'disable_web_page_preview' => true,
                ];

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

    private function endpoint(): string
    {
        return 'https://api.telegram.org/bot'.trim((string) $this->config['bot_token']).'/sendMessage';
    }
}
