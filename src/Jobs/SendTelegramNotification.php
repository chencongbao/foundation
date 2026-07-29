<?php

namespace Chencongbao\Foundation\Jobs;

use RuntimeException;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Chencongbao\Foundation\Services\Notification\TelegramNotificationSender;

final class SendTelegramNotification implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;
    public int $timeout;
    private string $message;
    private int $retryBackoff;

    public function __construct(string $message, int $tries = 3, int $timeout = 30, int $retryBackoff = 5)
    {
        $this->message = $message;
        $this->tries = max(1, $tries);
        $this->timeout = max(1, $timeout);
        $this->retryBackoff = max(0, $retryBackoff);
    }

    public function handle(TelegramNotificationSender $sender): void
    {
        if (!$sender->send($this->message)) {
            throw new RuntimeException('Foundation Telegram 通知发送失败。');
        }
    }

    public function backoff(): int
    {
        return $this->retryBackoff;
    }
}
