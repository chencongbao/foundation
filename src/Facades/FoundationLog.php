<?php

namespace Chencongbao\Foundation\Facades;

use Throwable;
use Illuminate\Support\Facades\Facade;
use Chencongbao\Foundation\Services\Logging\FoundationLogger;

/**
 * @method static void debug(string $module, string $message, array $context = [])
 * @method static void info(string $module, string $message, array $context = [])
 * @method static void warning(string $module, string $message, array $context = [])
 * @method static void error(string $module, string $message, array $context = [])
 * @method static void log(string $module, string $level, string $message, array $context = [])
 * @method static void message(string $module, string $message, array $context = [], string $level = 'info')
 * @method static void exception(string $module, Throwable $exception, array $context = [])
 *
 * @see FoundationLogger
 */
class FoundationLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FoundationLogger::class;
    }
}
