<?php

namespace Chencongbao\Foundation\Facades;

use Illuminate\Support\Facades\Facade;
use Chencongbao\Foundation\Services\Notification\TelegramMessenger;

/**
 * @method static TelegramMessenger withToken(string $botToken)
 * @method static TelegramMessenger to(array|string $chatIds)
 * @method static TelegramMessenger replyTo(int $messageId, bool $allowSendingWithoutReply = false)
 * @method static TelegramMessenger withButtons(array $buttons)
 * @method static TelegramMessenger withTitle(string $title)
 * @method static TelegramMessenger withoutAppName(bool $without = true)
 * @method static string format(mixed $content, string $format = 'text', array $options = [])
 * @method static bool sendText(string $text)
 * @method static bool sendHtml(string $html)
 * @method static bool sendJson(mixed $data)
 *
 * @see TelegramMessenger
 */
final class FoundationTelegram extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelegramMessenger::class;
    }
}
