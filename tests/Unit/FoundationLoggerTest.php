<?php

namespace Chencongbao\Foundation\Tests\Unit;

use Throwable;
use RuntimeException;
use Mockery;
use Illuminate\Log\LogManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Chencongbao\Foundation\Contracts\ExceptionNotifier;
use Chencongbao\Foundation\Services\Logging\FoundationLogger;

class FoundationLoggerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_logs_by_module_and_notifies_exceptions_with_sanitized_context(): void
    {
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('log')
            ->once()
            ->with('error', '[tron_rpc] RPC failed', Mockery::on(static function (array $context): bool {
                return $context['token'] === '[REDACTED]'
                    && $context['nested']['authorization'] === '[REDACTED]'
                    && $context['exception'] === RuntimeException::class
                    && $context['exception_message'] === 'RPC failed'
                    && is_string($context['file'])
                    && is_int($context['line'])
                    && is_array($context['trace'])
                    && is_array($context['previous'])
                    && is_array($context['runtime']);
            }));

        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('daily')->andReturn($channel);

        $notifier = new class implements ExceptionNotifier {
            public array $calls = [];

            public function notify(string $module, Throwable $exception, array $context = []): bool
            {
                $this->calls[] = compact('module', 'exception', 'context');

                return true;
            }
        };

        $logger = new FoundationLogger($logs, $notifier, $this->config());
        $logger->exception('tron_rpc', new RuntimeException('RPC failed'), [
            'token' => 'secret-token',
            'nested' => ['authorization' => 'Bearer secret'],
        ]);

        $this->assertCount(1, $notifier->calls);
        $this->assertSame('[REDACTED]', $notifier->calls[0]['context']['token']);
    }

    public function test_it_ignores_disabled_modules_and_messages_below_the_module_level(): void
    {
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldNotReceive('channel');

        $notifier = Mockery::mock(ExceptionNotifier::class);
        $notifier->shouldNotReceive('notify');
        $logger = new FoundationLogger($logs, $notifier, $this->config());

        $logger->debug('tron_rpc', 'filtered');
        $logger->error('client_ip', 'disabled');
    }

    public function test_it_builds_an_independent_daily_log_file_for_a_module_path(): void
    {
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('log')
            ->once()
            ->with('info', '[tron_rpc] request completed', ['method' => 'system.health']);

        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('build')
            ->once()
            ->with([
                'driver' => 'daily',
                'path' => '/tmp/foundation/tron_rpc.log',
                'level' => 'info',
            ])
            ->andReturn($channel);

        $notifier = Mockery::mock(ExceptionNotifier::class);
        $notifier->shouldNotReceive('notify');
        $config = $this->config();
        $config['modules']['tron_rpc'] += [
            'driver' => 'daily',
            'path' => '/tmp/foundation/tron_rpc.log',
        ];

        $logger = new FoundationLogger($logs, $notifier, $config);
        $logger->info('tron_rpc', 'request completed', ['method' => 'system.health']);
    }

    public function test_exception_notification_is_independent_from_the_file_log_switch(): void
    {
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('log')
            ->once()
            ->with('error', '[tron_rpc] failed', Mockery::type('array'));

        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('daily')->andReturn($channel);
        $logs->shouldNotReceive('build');

        $notifier = Mockery::mock(ExceptionNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->with('tron_rpc', Mockery::type(RuntimeException::class), ['request_id' => 'rpc-1'])
            ->andReturnTrue();

        $config = $this->config();
        $config['modules']['tron_rpc']['enabled'] = false;

        $logger = new FoundationLogger($logs, $notifier, $config);
        $logger->exception('tron_rpc', new RuntimeException('failed'), [
            'request_id' => 'rpc-1',
        ]);
    }

    public function test_client_ip_exceptions_also_use_the_global_notifier(): void
    {
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('log')
            ->once()
            ->with('error', '[client_ip] resolve failed', Mockery::type('array'));

        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('daily')->andReturn($channel);
        $logs->shouldNotReceive('build');

        $notifier = Mockery::mock(ExceptionNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->with('client_ip', Mockery::type(RuntimeException::class), ['host' => 'api.example.com'])
            ->andReturn(true);

        $logger = new FoundationLogger($logs, $notifier, $this->config());
        $logger->exception('client_ip', new RuntimeException('resolve failed'), [
            'host' => 'api.example.com',
        ]);
    }

    public function test_every_exception_is_written_locally_even_when_notifications_may_be_deduplicated(): void
    {
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('log')
            ->twice()
            ->with('error', '[tron_rpc] repeated failure', Mockery::type('array'));

        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('daily')->andReturn($channel);

        $notifier = Mockery::mock(ExceptionNotifier::class);
        $notifier->shouldReceive('notify')->twice()->andReturn(true, false);

        $config = $this->config();
        $config['modules']['tron_rpc']['enabled'] = false;

        $logger = new FoundationLogger($logs, $notifier, $config);
        $logger->exception('tron_rpc', new RuntimeException('repeated failure'));
        $logger->exception('tron_rpc', new RuntimeException('repeated failure'));
    }

    public function test_exception_log_contains_the_previous_exception_chain_without_arguments(): void
    {
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('log')
            ->once()
            ->with('error', '[tron_rpc] outer failure', Mockery::on(static function (array $context): bool {
                $frames = array_merge($context['trace'], $context['previous'][0]['trace']);

                return $context['previous'][0]['exception'] === RuntimeException::class
                    && $context['previous'][0]['message'] === 'root failure'
                    && array_reduce($frames, static function (bool $safe, array $frame): bool {
                        return $safe && !array_key_exists('args', $frame);
                    }, true);
            }));

        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('daily')->andReturn($channel);

        $notifier = Mockery::mock(ExceptionNotifier::class);
        $notifier->shouldReceive('notify')->once()->andReturnTrue();

        $previous = new RuntimeException('root failure');
        $exception = new RuntimeException('outer failure', 0, $previous);

        $logger = new FoundationLogger($logs, $notifier, $this->config());
        $logger->exception('tron_rpc', $exception);
    }

    public function test_exception_log_failure_does_not_block_the_notification(): void
    {
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andThrow(new RuntimeException('logging unavailable'));

        $notifier = Mockery::mock(ExceptionNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->with('tron_rpc', Mockery::type(RuntimeException::class), ['request_id' => 'rpc-2'])
            ->andReturnTrue();

        $logger = new FoundationLogger($logs, $notifier, $this->config());
        $logger->exception('tron_rpc', new RuntimeException('RPC failed'), [
            'request_id' => 'rpc-2',
        ]);
    }

    public function test_normal_message_only_writes_a_local_log(): void
    {
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('log')
            ->once()
            ->with('warning', '[payment] payment delayed', [
                'order_no' => 'P100',
                'token' => '[REDACTED]',
            ]);

        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('daily')->andReturn($channel);

        $exceptionNotifier = Mockery::mock(ExceptionNotifier::class);
        $exceptionNotifier->shouldNotReceive('notify');

        $config = $this->config();
        $config['modules']['payment'] = [
            'enabled' => true,
            'channel' => 'daily',
            'level' => 'debug',
        ];

        $logger = new FoundationLogger($logs, $exceptionNotifier, $config);
        $logger->message('payment', 'payment delayed', [
            'order_no' => 'P100',
            'token' => 'secret-token',
        ], 'warning');
    }

    public function test_normal_message_does_nothing_when_the_module_log_is_disabled(): void
    {
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldNotReceive('channel');
        $logs->shouldNotReceive('build');

        $exceptionNotifier = Mockery::mock(ExceptionNotifier::class);
        $exceptionNotifier->shouldNotReceive('notify');

        $config = $this->config();
        $config['modules']['payment'] = [
            'enabled' => false,
        ];

        $logger = new FoundationLogger($logs, $exceptionNotifier, $config);
        $logger->message('payment', 'manual alert', ['order_no' => 'P101']);
    }

    public function test_it_resolves_the_date_placeholder_to_a_daily_directory(): void
    {
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('log')->once();

        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('build')
            ->once()
            ->with([
                'driver' => 'single',
                'path' => '/tmp/logs/'.date('Y-m-d').'/foundation/client_ip.log',
                'level' => 'info',
            ])
            ->andReturn($channel);

        $notifier = Mockery::mock(ExceptionNotifier::class);
        $config = $this->config();
        $config['modules']['client_ip'] = [
            'enabled' => true,
            'driver' => 'single',
            'path' => '/tmp/logs/{date}/foundation/client_ip.log',
            'level' => 'info',
        ];

        $logger = new FoundationLogger($logs, $notifier, $config);
        $logger->info('client_ip', 'resolved');
    }

    private function config(): array
    {
        return [
            'default' => [
                'enabled' => true,
                'channel' => 'stack',
                'level' => 'debug',
            ],
            'exception' => [
                'channel' => 'daily',
                'path' => null,
                'level' => 'error',
            ],
            'modules' => [
                'tron_rpc' => [
                    'channel' => 'daily',
                    'level' => 'info',
                ],
                'client_ip' => [
                    'enabled' => false,
                ],
            ],
            'sensitive_keys' => ['authorization', 'secret', 'token'],
        ];
    }
}
