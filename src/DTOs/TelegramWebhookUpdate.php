<?php

namespace Chencongbao\Foundation\DTOs;

/**
 * Telegram Webhook Update 的只读数据包装。
 */
final class TelegramWebhookUpdate
{
    private array $payload;
    private string $raw;

    public function __construct(array $payload, string $raw)
    {
        $this->payload = $payload;
        $this->raw = $raw;
    }

    public function all(): array
    {
        return $this->payload;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function updateId(): ?int
    {
        return isset($this->payload['update_id'])
            ? (int) $this->payload['update_id']
            : null;
    }

    public function message(): array
    {
        return (array) ($this->payload['message'] ?? []);
    }

    public function callbackQuery(): array
    {
        return (array) ($this->payload['callback_query'] ?? []);
    }

    public function chat(): array
    {
        $message = $this->message();
        if ($message !== []) {
            return (array) ($message['chat'] ?? []);
        }

        return (array) ($this->callbackQuery()['message']['chat'] ?? []);
    }

    public function chatId(): int|string|null
    {
        $chatId = $this->chat()['id'] ?? null;

        return is_int($chatId) || is_string($chatId) ? $chatId : null;
    }

    public function text(): ?string
    {
        $text = $this->message()['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    public function isCommand(): bool
    {
        $text = $this->text();

        return $text !== null && str_starts_with(ltrim($text), '/');
    }

    public function command(): ?string
    {
        if (!$this->isCommand()) {
            return null;
        }

        $text = ltrim((string) $this->text());
        $command = explode(' ', $text, 2)[0];

        return strtolower(explode('@', $command, 2)[0]);
    }

    public function isCallbackQuery(): bool
    {
        return $this->callbackQuery() !== [];
    }
}
