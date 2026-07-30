<?php

namespace Chencongbao\Foundation\Tests\Unit;

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Chencongbao\Foundation\DTOs\TelegramWebhookUpdate;
use Chencongbao\Foundation\Services\Notification\TelegramWebhookHandler;

class TelegramWebhookHandlerTest extends TestCase
{
    public function test_it_parses_commands_and_deduplicates_update_ids(): void
    {
        $handler = $this->handler();
        $calls = [];
        $request = $this->request([
            'update_id' => 9527,
            'message' => [
                'text' => '/SUCCESS_RATE@my_bot merchant-1',
                'chat' => ['id' => -1001],
            ],
        ]);

        $first = $handler->handle($request, static function (TelegramWebhookUpdate $update) use (&$calls): void {
            $calls[] = [
                'id' => $update->updateId(),
                'chat_id' => $update->chatId(),
                'command' => $update->command(),
                'is_command' => $update->isCommand(),
            ];
        });
        $second = $handler->handle($request, static function () use (&$calls): void {
            $calls[] = ['duplicate'];
        });

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('ok', $first->getContent());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame([[
            'id' => 9527,
            'chat_id' => -1001,
            'command' => '/success_rate',
            'is_command' => true,
        ]], $calls);
    }

    public function test_it_rejects_an_invalid_secret_token(): void
    {
        $handler = $this->handler([
            'secret_token' => 'expected-secret',
        ]);
        $called = false;

        $response = $handler->handle(
            $this->request(['update_id' => 1], 'wrong-secret'),
            static function () use (&$called): void {
                $called = true;
            }
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($called);
    }

    public function test_dynamic_secret_token_does_not_modify_the_original_singleton(): void
    {
        $handler = $this->handler();
        $request = $this->request(['message' => ['text' => 'hello']], 'dynamic-secret');

        $this->assertSame(
            200,
            $handler->withSecretToken('dynamic-secret')->handle($request, static function (): void {
            })->getStatusCode()
        );
        $this->assertSame(
            200,
            $handler->handle($this->request(['message' => ['text' => 'hello']]), static function (): void {
            })->getStatusCode()
        );
    }

    public function test_it_exposes_callback_query_helpers(): void
    {
        $handler = $this->handler();
        $captured = null;

        $handler->handle($this->request([
            'update_id' => 2,
            'callback_query' => [
                'id' => 'callback-1',
                'data' => 'approve',
                'message' => [
                    'chat' => ['id' => 123],
                ],
            ],
        ]), static function (TelegramWebhookUpdate $update) use (&$captured): void {
            $captured = [
                'callback' => $update->isCallbackQuery(),
                'chat_id' => $update->chatId(),
                'data' => $update->callbackQuery()['data'],
            ];
        });

        $this->assertSame([
            'callback' => true,
            'chat_id' => 123,
            'data' => 'approve',
        ], $captured);
    }

    public function test_it_runs_the_exception_callback_and_always_acknowledges_telegram(): void
    {
        $handler = $this->handler();
        $captured = [];

        $response = $handler->handle(
            $this->request(['update_id' => 3, 'message' => ['text' => 'hello']]),
            static function (): void {
                throw new RuntimeException('request to bot123456:secret-token/sendMessage failed');
            },
            static function (
                RuntimeException $exception,
                TelegramWebhookUpdate $update
            ) use (&$captured, $handler): void {
                $captured = [
                    'message' => $handler->sanitizeError(
                        $exception->getMessage(),
                        '123456:secret-token'
                    ),
                    'update_id' => $update->updateId(),
                ];
            }
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $captured['update_id']);
        $this->assertStringNotContainsString('secret-token', $captured['message']);
    }

    private function handler(array $config = []): TelegramWebhookHandler
    {
        return new TelegramWebhookHandler(
            new Repository(new ArrayStore()),
            $config + [
                'deduplicate_seconds' => 600,
                'cache_prefix' => 'test:telegram:webhook:',
            ]
        );
    }

    private function request(array $payload, ?string $secretToken = null): Request
    {
        $server = [];
        if ($secretToken !== null) {
            $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secretToken;
        }

        return Request::create(
            '/telegram/webhook',
            'POST',
            [],
            [],
            [],
            $server,
            (string) json_encode($payload)
        );
    }
}
