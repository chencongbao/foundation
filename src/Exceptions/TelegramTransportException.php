<?php

namespace Chencongbao\Foundation\Exceptions;

use RuntimeException;

/**
 * Telegram 通知传输失败。
 *
 * 异常仍会从 Queue Job 抛出以触发重试和 failed_jobs，但已由 Foundation 写入
 * telegram.log，因此阻止 Laravel 再次进入全局异常上报，避免递归发送通知。
 */
final class TelegramTransportException extends RuntimeException
{
    /**
     * Laravel 发现异常自身提供 report() 且返回非 false 时，视为已经完成上报。
     */
    public function report(): bool
    {
        return true;
    }
}
