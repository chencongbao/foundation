<?php

namespace Chencongbao\Foundation\Tests\Unit;

use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Chencongbao\Foundation\Jobs\SendTelegramNotification;
use Chencongbao\Foundation\Services\Notification\TelegramNotificationSender;

class SendTelegramNotificationTest extends TestCase
{
    public function test_the_job_sends_the_queued_message(): void
    {
        $messages = [];
        $sender = $this->sender(200, '{"ok":true,"result":{}}', $messages);
        $job = new SendTelegramNotification('queued message');

        $job->handle($sender);

        $this->assertSame(['queued message'], $messages);
    }

    public function test_the_job_throws_so_the_queue_can_retry_a_failed_request(): void
    {
        $messages = [];
        $sender = $this->sender(500, '{"ok":false}', $messages);
        $job = new SendTelegramNotification('failed message');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Foundation Telegram 通知发送失败。');

        $job->handle($sender);
    }

    private function sender(int $status, string $body, array &$messages): TelegramNotificationSender
    {
        $handler = static function (RequestInterface $request, array $_options) use ($status, $body, &$messages) {
            parse_str((string) $request->getBody(), $params);
            $messages[] = $params['text'] ?? '';

            return Create::promiseFor(new Response($status, [
                'Content-Type' => 'application/json',
            ], $body));
        };

        return new TelegramNotificationSender(new Client(['handler' => $handler]), [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
        ]);
    }
}
