<?php

namespace Chencongbao\Foundation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Chencongbao\Foundation\Exceptions\TelegramTransportException;
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
            $sender->reportFailure('Telegram 队列任务发送失败，等待重试', [
                'attempt' => $this->attempts(),
                'tries' => $this->tries,
                'queue' => $this->queue,
                'connection' => $this->connection,
                'message_hash' => hash('sha256', $this->message),
            ]);

            throw new TelegramTransportException('Foundation Telegram 通知发送失败。');
        }
    }

    public function backoff(): int
    {
        return $this->retryBackoff;
    }
}
