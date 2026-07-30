<?php

namespace Chencongbao\Foundation\Tests\Unit;

use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use Illuminate\Log\LogManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestInterface;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Chencongbao\Foundation\Jobs\SendTelegramNotification;
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Foundation Telegram 通知发送失败。');

        $job->handle($sender);
    }

    public function test_it_writes_detailed_failure_logs_for_a_telegram_api_error(): void
    {
        $messages = [];
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('[telegram] Telegram API 返回发送失败', \Mockery::on(static function (array $context): bool {
                return $context['chat_id'] === '-1001'
                    && $context['http_status'] === 400
                    && $context['telegram_error_code'] === 400
                    && $context['telegram_description'] === 'Bad Request: chat not found'
                    && $context['response_is_json'] === true
                    && strlen($context['message_hash']) === 64;
            }));

        $logs = \Mockery::mock(LogManager::class);
        $logs->shouldReceive('build')
            ->once()
            ->with([
                'driver' => 'single',
                'path' => '/tmp/logs/'.date('Y-m-d').'/foundation/telegram.log',
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
                'path' => '/tmp/logs/{date}/foundation/telegram.log',
                'level' => 'error',
            ],
        ], $logs);

        $this->assertFalse($sender->send('failed message'));
    }

    public function test_failure_log_context_redacts_the_bot_token_and_sensitive_keys(): void
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('[telegram] test failure', [
                'exception_message' => 'request to bot[REDACTED]/sendMessage failed',
                'bot_token' => '[REDACTED]',
                'nested' => [
                    'authorization' => '[REDACTED]',
                ],
            ]);

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
