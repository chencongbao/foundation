<?php

namespace Chencongbao\Foundation\Tests\Unit;

use Mockery;
use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use Illuminate\Log\LogManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
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
        $message = json_decode($requests[0]['params']['text'], true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('[Robots] Foundation 异常通知', $message['标题']);
        $this->assertSame('production', $message['运行环境']);
        $this->assertSame('tron_rpc', $message['功能模块']);
        $this->assertSame(RuntimeException::class, $message['异常类型']);
        $this->assertSame('RPC failed', $message['异常消息']);
        $this->assertSame(0, $message['错误代码']);
        $this->assertIsString($message['发生时间']);
        $this->assertSame([
            'endpoint' => '127.0.0.1:9600',
        ], $message['上下文']);
    }

    public function test_it_keeps_the_original_context_inside_the_json_message(): void
    {
        $requests = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'application' => 'FallbackNode',
            'queue' => ['enabled' => false],
        ];
        $notifier = $this->notifier($requests, $config);

        $this->assertTrue($notifier->notify('tron_exception', new RuntimeException('provider switched'), [
            'node' => 'tronweb4',
            'pool' => 'fullnode',
            'backup_provider' => 'provider #2',
        ]));

        $message = json_decode($requests[0]['params']['text'], true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('[FallbackNode] Foundation 异常通知', $message['标题']);
        $this->assertSame('tron_exception', $message['功能模块']);
        $this->assertSame('tronweb4', $message['上下文']['node']);
        $this->assertSame('fullnode', $message['上下文']['pool']);
        $this->assertSame('provider #2', $message['上下文']['backup_provider']);
    }

    public function test_an_oversized_notification_remains_valid_json(): void
    {
        $requests = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'application' => 'tronweb4',
            'queue' => ['enabled' => false],
        ];
        $notifier = $this->notifier($requests, $config);

        $this->assertTrue($notifier->notify('tron_exception', new RuntimeException(str_repeat('异常内容', 2000)), [
            'node' => 'tronweb4',
            'primary_error' => str_repeat('timeout ', 2000),
        ]));

        $text = $requests[0]['params']['text'];
        $message = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(3900, strlen($text));
        $this->assertTrue($message['上下文']['truncated']);
        $this->assertSame(64, strlen($message['上下文']['sha256']));
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

        $this->assertTrue($notifier->notify('payment', new RuntimeException('queued exception')));
        $this->assertSame([], $requests);
    }

    public function test_it_uses_the_notice_queue_when_no_queue_name_is_configured(): void
    {
        $requests = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'queue' => [
                'enabled' => true,
            ],
        ];
        $sender = new TelegramNotificationSender($this->client($requests), $config);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static function ($job): bool {
                return $job instanceof SendTelegramNotification
                    && $job->connection === null
                    && $job->queue === 'notice';
            }))
            ->andReturn('job-id');
        $notifier = new TelegramExceptionNotifier($sender, $dispatcher, $config);

        $this->assertTrue($notifier->notify('tron_rpc', new RuntimeException('queued exception')));
        $this->assertSame([], $requests);
    }

    public function test_it_logs_a_queue_dispatch_failure(): void
    {
        $requests = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'queue' => [
                'enabled' => true,
                'connection' => 'redis',
                'name' => 'notice',
            ],
            'failure_log' => [
                'channel' => 'daily',
                'path' => null,
            ],
        ];

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('[telegram] Telegram 通知任务投递队列失败', Mockery::on(static function (array $context): bool {
                return $context['exception'] === RuntimeException::class
                    && $context['exception_message'] === 'Redis unavailable'
                    && $context['queue'] === 'notice'
                    && $context['connection'] === 'redis'
                    && strlen($context['message_hash']) === 64;
            }));
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('daily')->andReturn($logger);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Redis unavailable'));

        $notifier = new TelegramExceptionNotifier(
            new TelegramNotificationSender($this->client($requests), $config, $logs),
            $dispatcher,
            $config
        );

        $this->assertFalse($notifier->notify('tron_rpc', new RuntimeException('RPC failed')));
        $this->assertSame([], $requests);
    }

    public function test_it_only_sends_the_same_exception_once_during_the_deduplication_window(): void
    {
        $requests = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'deduplicate_seconds' => 300,
            'queue' => ['enabled' => false],
        ];
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('add')
            ->twice()
            ->with(
                Mockery::on(static fn ($key): bool => str_starts_with(
                    (string) $key,
                    'foundation:telegram:exception:'
                )),
                true,
                300
            )
            ->andReturn(true, false);
        $notifier = new TelegramExceptionNotifier(
            new TelegramNotificationSender($this->client($requests), $config),
            Mockery::mock(Dispatcher::class),
            $config,
            $cache
        );

        $this->assertTrue($notifier->notify(
            'tron_rpc',
            new RuntimeException('RPC unavailable'),
            ['endpoint' => 'node-1']
        ));
        $this->assertTrue($notifier->notify(
            'tron_rpc',
            new RuntimeException('RPC unavailable'),
            ['endpoint' => 'node-2']
        ));
        $this->assertCount(1, $requests);
    }

    public function test_it_does_not_merge_exceptions_with_different_messages(): void
    {
        $requests = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'deduplicate_seconds' => 300,
            'queue' => ['enabled' => false],
        ];
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('add')->twice()->andReturn(true);
        $notifier = new TelegramExceptionNotifier(
            new TelegramNotificationSender($this->client($requests), $config),
            Mockery::mock(Dispatcher::class),
            $config,
            $cache
        );

        $this->assertTrue($notifier->notify('tron_rpc', new RuntimeException('node-1 failed')));
        $this->assertTrue($notifier->notify('tron_rpc', new RuntimeException('node-2 failed')));
        $this->assertCount(2, $requests);
    }

    public function test_configured_context_keys_can_separate_otherwise_identical_exceptions(): void
    {
        $requests = [];
        $cacheKeys = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'deduplicate_seconds' => 300,
            'deduplicate_context_keys' => ['node'],
            'queue' => ['enabled' => false],
        ];
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('add')
            ->twice()
            ->andReturnUsing(static function ($key, $value, $ttl) use (&$cacheKeys): bool {
                $cacheKeys[] = $key;

                return true;
            });
        $notifier = new TelegramExceptionNotifier(
            new TelegramNotificationSender($this->client($requests), $config),
            Mockery::mock(Dispatcher::class),
            $config,
            $cache
        );

        $exceptionMessage = 'TRON fullnode primary provider switched to provider #2.';
        $this->assertTrue($notifier->notify(
            'tron_rpc',
            new RuntimeException($exceptionMessage),
            ['node' => 'tronweb1']
        ));
        $this->assertTrue($notifier->notify(
            'tron_rpc',
            new RuntimeException($exceptionMessage),
            ['node' => 'tronweb2']
        ));

        $this->assertCount(2, $requests);
        $this->assertNotSame($cacheKeys[0], $cacheKeys[1]);
    }

    public function test_excluded_exception_classes_are_sent_every_time_without_using_the_cache(): void
    {
        $requests = [];
        $config = [
            'enabled' => true,
            'bot_token' => '123456:test-token',
            'chat_ids' => ['-1001'],
            'timeout_seconds' => 3,
            'deduplicate_seconds' => 300,
            'deduplicate_exclude_exceptions' => [
                CriticalNotificationException::class,
            ],
            'queue' => ['enabled' => false],
        ];
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldNotReceive('add');
        $notifier = new TelegramExceptionNotifier(
            new TelegramNotificationSender($this->client($requests), $config),
            Mockery::mock(Dispatcher::class),
            $config,
            $cache
        );

        $this->assertTrue($notifier->notify(
            'tron_rpc',
            new CriticalNotificationException('all providers unavailable')
        ));
        $this->assertTrue($notifier->notify(
            'tron_rpc',
            new CriticalNotificationException('all providers unavailable')
        ));
        $this->assertCount(2, $requests);
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

class CriticalNotificationException extends RuntimeException
{
}
