<?php

namespace Chencongbao\Foundation\Tests\Unit;

use Mockery;
use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Chencongbao\Foundation\Jobs\SendTelegramNotification;
use Chencongbao\Foundation\Services\Notification\TelegramExceptionNotifier;
use Chencongbao\Foundation\Services\Notification\TelegramNotificationSender;

class TelegramExceptionNotifierTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_does_not_send_when_telegram_is_not_fully_configured(): void
    {
        $requests = [];
        $config = [
            'enabled' => false,
            'bot_token' => '',
            'chat_ids' => [],
            'timeout_seconds' => 3,
        ];
        $notifier = $this->notifier($requests, $config);

        $this->assertFalse($notifier->notify('tron_rpc', new RuntimeException('failed')));
        $this->assertSame([], $requests);
    }

    public function test_it_sends_an_exception_to_every_configured_chat(): void
    {
        $requests = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001', '-1002'],
            'message_thread_id' => 88,
            'timeout_seconds' => 3,
            'application' => 'Robots',
            'environment' => 'production',
            'queue' => ['enabled' => false],
        ];
        $notifier = $this->notifier($requests, $config);

        $this->assertTrue($notifier->notify('tron_rpc', new RuntimeException('RPC failed'), [
            'endpoint' => '127.0.0.1:9600',
        ]));
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('/bot123456:test-token/sendMessage', $requests[0]['uri']);
        $this->assertSame('-1001', $requests[0]['params']['chat_id']);
        $this->assertSame('88', $requests[0]['params']['message_thread_id']);
        $this->assertStringContainsString('Module: tron_rpc', $requests[0]['params']['text']);
    }

    public function test_it_sends_a_normal_message_without_an_exception_payload(): void
    {
        $requests = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'application' => 'Robots',
            'environment' => 'production',
            'queue' => ['enabled' => false],
        ];
        $notifier = $this->notifier($requests, $config);

        $this->assertTrue($notifier->notifyMessage('payment', 'payment delayed', [
            'order_no' => 'P100',
        ]));
        $this->assertCount(1, $requests);
        $this->assertStringContainsString('Foundation message', $requests[0]['params']['text']);
        $this->assertStringContainsString('Module: payment', $requests[0]['params']['text']);
        $this->assertStringContainsString('Message: payment delayed', $requests[0]['params']['text']);
        $this->assertStringNotContainsString('Exception:', $requests[0]['params']['text']);
    }

    public function test_it_dispatches_telegram_notifications_to_the_configured_queue(): void
    {
        $requests = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'application' => 'Robots',
            'environment' => 'production',
            'queue' => [
                'enabled' => true,
                'connection' => 'redis',
                'name' => 'foundation-notifications',
                'tries' => 4,
                'timeout_seconds' => 40,
                'backoff_seconds' => 8,
            ],
        ];
        $sender = new TelegramNotificationSender($this->client($requests), $config);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static function ($job): bool {
                return $job instanceof SendTelegramNotification
                    && $job->connection === 'redis'
                    && $job->queue === 'foundation-notifications'
                    && $job->tries === 4
                    && $job->timeout === 40
                    && $job->backoff() === 8;
            }))
            ->andReturn('job-id');
        $notifier = new TelegramExceptionNotifier($sender, $dispatcher, $config);

        $this->assertTrue($notifier->notifyMessage('payment', 'queued message'));
        $this->assertSame([], $requests);
    }

    private function notifier(array &$requests, array $config): TelegramExceptionNotifier
    {
        return new TelegramExceptionNotifier(
            new TelegramNotificationSender($this->client($requests), $config),
            Mockery::mock(Dispatcher::class),
            $config
        );
    }

    private function client(array &$requests): Client
    {
        $handler = static function (RequestInterface $request, array $_options) use (&$requests) {
            parse_str((string) $request->getBody(), $params);
            $requests[] = [
                'uri' => (string) $request->getUri(),
                'params' => $params,
            ];

            return Create::promiseFor(new Response(200, [
                'Content-Type' => 'application/json',
            ], '{"ok":true,"result":{}}'));
        };

        return new Client(['handler' => $handler]);
    }
}
