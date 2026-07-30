<?php

namespace Chencongbao\Foundation\Facades;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Chencongbao\Foundation\Services\Notification\TelegramWebhookHandler;

/**
 * @method static TelegramWebhookHandler withSecretToken(string $secretToken)
 * @method static Response handle(Request $request, callable $handler, ?callable $onException = null)
 * @method static string sanitizeError(string $message, string $botToken = '')
 * @method static string maskToken(string $token)
 *
 * @see TelegramWebhookHandler
 */
final class FoundationTelegramWebhook extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelegramWebhookHandler::class;
    }
}
