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

    public function test_it_logs_by_module_and_notifies_enabled_exceptions_with_sanitized_context(): void
    {
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('log')
            ->once()
            ->with('error', '[tron_rpc] RPC failed', Mockery::on(static function (array $context): bool {
                return $context['token'] === '[REDACTED]'
                    && $context['nested']['authorization'] === '[REDACTED]'
                    && $context['exception'] === RuntimeException::class;
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
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldNotReceive('channel');
        $logs->shouldNotReceive('build');

        $notifier = Mockery::mock(ExceptionNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->with('tron_rpc', Mockery::type(RuntimeException::class), ['request_id' => 'rpc-1'])
            ->andReturnTrue();

        $config = $this->config();
        $config['modules']['tron_rpc']['enabled'] = false;
        $config['modules']['tron_rpc']['notify'] = true;

        $logger = new FoundationLogger($logs, $notifier, $config);
        $logger->exception('tron_rpc', new RuntimeException('failed'), [
            'request_id' => 'rpc-1',
        ]);
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
            'notify' => false,
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
                'notify' => false,
            ],
            'modules' => [
                'tron_rpc' => [
                    'channel' => 'daily',
                    'level' => 'info',
                    'notify' => true,
                ],
                'client_ip' => [
                    'enabled' => false,
                ],
            ],
            'sensitive_keys' => ['authorization', 'secret', 'token'],
        ];
    }
}
