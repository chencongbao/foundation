<?php

namespace Chencongbao\Foundation\Facades;

use Illuminate\Support\Facades\Facade;
use Chencongbao\Foundation\Contracts\MessageNotifier;

/**
 * @method static bool message(string $module, string $message, array $context = [])
 *
 * @see MessageNotifier
 */
class FoundationNotify extends Facade
{
    public static function message(string $module, string $message, array $context = []): bool
    {
        return static::getFacadeRoot()->notifyMessage($module, $message, $context);
    }

    protected static function getFacadeAccessor(): string
    {
        return MessageNotifier::class;
    }
}
