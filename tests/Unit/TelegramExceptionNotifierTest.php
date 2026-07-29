<?php

namespace Chencongbao\Foundation\Tests\Unit;

use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Chencongbao\Foundation\Services\Notification\TelegramExceptionNotifier;

class TelegramExceptionNotifierTest extends TestCase
{
    public function test_it_does_not_send_when_telegram_is_not_fully_configured(): void
    {
        $requests = [];
        $notifier = new TelegramExceptionNotifier($this->client($requests), [
            'enabled' => false,
            'bot_token' => '',
            'chat_ids' => [],
            'timeout_seconds' => 3,
        ]);

        $this->assertFalse($notifier->notify('tron_rpc', new RuntimeException('failed')));
        $this->assertSame([], $requests);
    }

    public function test_it_sends_an_exception_to_every_configured_chat(): void
    {
        $requests = [];
        $notifier = new TelegramExceptionNotifier($this->client($requests), [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001', '-1002'],
            'message_thread_id' => 88,
            'timeout_seconds' => 3,
            'application' => 'Robots',
            'environment' => 'production',
        ]);

        $this->assertTrue($notifier->notify('tron_rpc', new RuntimeException('RPC failed'), [
            'endpoint' => '127.0.0.1:9600',
        ]));
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('/bot123456:test-token/sendMessage', $requests[0]['uri']);
        $this->assertSame('-1001', $requests[0]['params']['chat_id']);
        $this->assertSame('88', $requests[0]['params']['message_thread_id']);
        $this->assertStringContainsString('Module: tron_rpc', $requests[0]['params']['text']);
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
