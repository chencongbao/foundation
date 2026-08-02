<?php

namespace Chencongbao\Foundation\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use Illuminate\Log\LogManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestInterface;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Chencongbao\Foundation\Jobs\SendTelegramNotification;
use Chencongbao\Foundation\Exceptions\TelegramTransportException;
use Chencongbao\Foundation\Services\Notification\TelegramNotificationSender;

class SendTelegramNotificationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

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

        $this->expectException(TelegramTransportException::class);
        $this->expectExceptionMessage('Foundation Telegram 通知发送失败。');

        $job->handle($sender);
    }

    public function test_transport_exception_marks_itself_as_already_reported(): void
    {
        $exception = new TelegramTransportException('failed');

        $this->assertTrue($exception->report());
    }

    public function test_it_writes_detailed_failure_logs_for_a_telegram_api_error(): void
    {
        $messages = [];
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with(\Mockery::on(static function (string $message): bool {
                return str_contains($message, 'Telegram 通知失败')
                    && str_contains($message, '错误说明：Telegram API 返回发送失败')
                    && str_contains($message, '"chat_id": "-1001"')
                    && str_contains($message, '"http_status": 400')
                    && str_contains($message, '"telegram_error_code": 400')
                    && str_contains($message, '"telegram_description": "Bad Request: chat not found"')
                    && str_contains($message, '"response_is_json": true')
                    && str_contains($message, '"message_hash":');
            }), []);

        $logs = \Mockery::mock(LogManager::class);
        $logs->shouldReceive('build')
            ->once()
            ->with([
                'driver' => 'single',
                'path' => '/tmp/logs/'.date('Y-m-d').'/foundation/telegram_failure.log',
                'level' => 'error',
            ])
            ->andReturn($logger);

        $handler = static function (RequestInterface $_request, array $_options) {
            return Create::promiseFor(new Response(400, [
                'Content-Type' => 'application/json',
            ], '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}'));
        };
        $sender = new TelegramNotificationSender(new Client(['handler' => $handler]), [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'failure_log' => [
                'driver' => 'single',
                'path' => '/tmp/logs/{date}/foundation/telegram_failure.log',
                'level' => 'error',
            ],
        ], $logs);

        $this->assertFalse($sender->send('failed message'));
    }

    public function test_it_writes_a_readable_success_log_without_the_message_body(): void
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with(\Mockery::on(static function (string $message): bool {
                return str_contains($message, 'Telegram 操作日志')
                    && str_contains($message, '应用名称：robots')
                    && str_contains($message, '操作说明：Telegram 消息发送成功')
                    && str_contains($message, '"chat_id": "-1001"')
                    && str_contains($message, '"http_status": 200')
                    && str_contains($message, '"telegram_message_id": 9527')
                    && str_contains($message, '"message_bytes": 20')
                    && str_contains($message, '"message_hash":')
                    && str_contains($message, '"duration_ms":')
                    && !str_contains($message, 'private message body');
            }), []);

        $logs = \Mockery::mock(LogManager::class);
        $logs->shouldReceive('build')
            ->once()
            ->with([
                'driver' => 'single',
                'path' => '/tmp/logs/'.date('Y-m-d').'/foundation/telegram.log',
                'level' => 'info',
            ])
            ->andReturn($logger);

        $handler = static function (RequestInterface $_request, array $_options) {
            return Create::promiseFor(new Response(200, [
                'Content-Type' => 'application/json',
            ], '{"ok":true,"result":{"message_id":9527}}'));
        };
        $sender = new TelegramNotificationSender(new Client(['handler' => $handler]), [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'application' => 'robots',
            'activity_log' => [
                'enabled' => true,
                'driver' => 'single',
                'path' => '/tmp/logs/{date}/foundation/telegram.log',
                'level' => 'info',
            ],
        ], $logs);

        $this->assertTrue($sender->send('private message body'));
    }

    public function test_bot_api_success_log_does_not_contain_webhook_secrets(): void
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with(\Mockery::on(static function (string $message): bool {
                return str_contains($message, '操作说明：Telegram Bot API 调用成功')
                    && str_contains($message, '"method": "setWebhook"')
                    && str_contains($message, '"http_status": 200')
                    && str_contains($message, '"result_type": "bool"')
                    && !str_contains($message, 'webhook-secret')
                    && !str_contains($message, 'private-hook-path');
            }), []);

        $logs = \Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('stack')->andReturn($logger);

        $handler = static function (RequestInterface $_request, array $_options) {
            return Create::promiseFor(new Response(200, [
                'Content-Type' => 'application/json',
            ], '{"ok":true,"result":true}'));
        };
        $sender = new TelegramNotificationSender(new Client(['handler' => $handler]), [
            'bot_token' => '123456:test-token',
            'application' => 'robots',
            'activity_log' => [
                'enabled' => true,
                'channel' => 'stack',
                'path' => null,
                'level' => 'info',
            ],
        ], $logs);

        $this->assertTrue($sender->call('setWebhook', [
            'url' => 'https://example.com/private-hook-path',
            'secret_token' => 'webhook-secret',
        ]));
    }

    public function test_success_operation_logs_can_be_disabled(): void
    {
        $logs = \Mockery::mock(LogManager::class);
        $logs->shouldNotReceive('build');
        $logs->shouldNotReceive('channel');

        $handler = static function (RequestInterface $_request, array $_options) {
            return Create::promiseFor(new Response(200, [
                'Content-Type' => 'application/json',
            ], '{"ok":true,"result":{"message_id":9527}}'));
        };
        $sender = new TelegramNotificationSender(new Client(['handler' => $handler]), [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'activity_log' => [
                'enabled' => false,
            ],
        ], $logs);

        $this->assertTrue($sender->send('message'));
    }

    public function test_it_writes_a_safe_success_log_for_a_photo(): void
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with(\Mockery::on(static function (string $message): bool {
                return str_contains($message, '操作说明：Telegram 图片发送成功')
                    && str_contains($message, '"photo_source": "remote_or_file_id"')
                    && str_contains($message, '"telegram_message_id": 9528')
                    && str_contains($message, '"photo_reference_hash":')
                    && str_contains($message, '"caption_hash":')
                    && !str_contains($message, 'private.example.com/order.png')
                    && !str_contains($message, 'private photo caption');
            }), []);

        $logs = \Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('stack')->andReturn($logger);
        $handler = static function (RequestInterface $_request, array $_options) {
            return Create::promiseFor(new Response(200, [
                'Content-Type' => 'application/json',
            ], '{"ok":true,"result":{"message_id":9528}}'));
        };
        $sender = new TelegramNotificationSender(new Client(['handler' => $handler]), [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'application' => 'robots',
            'activity_log' => [
                'enabled' => true,
                'channel' => 'stack',
                'path' => null,
                'level' => 'info',
            ],
        ], $logs);

        $this->assertTrue($sender->sendPhoto(
            'https://private.example.com/order.png?token=secret',
            'private photo caption'
        ));
    }

    public function test_failure_log_context_redacts_the_bot_token_and_sensitive_keys(): void
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with(\Mockery::on(static function (string $message): bool {
                return str_contains($message, '错误说明：test failure')
                    && str_contains($message, '"exception_message": "request to bot[REDACTED]/sendMessage failed"')
                    && str_contains($message, '"bot_token": "[REDACTED]"')
                    && str_contains($message, '"authorization": "[REDACTED]"');
            }), []);

        $logs = \Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('stack')->andReturn($logger);

        $sender = new TelegramNotificationSender(new Client(), [
            'bot_token' => '123456:test-token',
            'failure_log' => [
                'channel' => 'stack',
                'path' => null,
            ],
        ], $logs);
        $sender->reportFailure('test failure', [
            'exception_message' => 'request to bot123456:test-token/sendMessage failed',
            'bot_token' => '123456:test-token',
            'nested' => [
                'authorization' => 'Bearer secret',
            ],
        ]);
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
