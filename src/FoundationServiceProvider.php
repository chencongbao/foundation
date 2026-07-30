<?php

namespace Chencongbao\Foundation;

use GuzzleHttp\Client;
use Illuminate\Log\LogManager;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Chencongbao\Foundation\Services\Tron\TronRpcClient;
use Chencongbao\Foundation\Contracts\ClientIpResolver;
use Chencongbao\Foundation\Contracts\ExceptionNotifier;
use Chencongbao\Foundation\Support\ConfigMerger;
use Chencongbao\Foundation\Services\Logging\FoundationLogger;
use Chencongbao\Foundation\Services\Http\TrustedProxyClientIpResolver;
use Chencongbao\Foundation\Services\Notification\TelegramExceptionNotifier;
use Chencongbao\Foundation\Services\Notification\TelegramNotificationSender;

/**
 * 注册 foundation 包提供的共享基础服务。
 *
 * TRON RPC Client 以 singleton 形式存在，使常驻队列 Worker 能持续轮转多个内网节点；
 * 配置缓存后所有参数均来自 Laravel config，不在业务代码中分散读取环境变量。
 */
class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/foundation.php', 'foundation');
        $this->mergeConfigFrom(__DIR__.'/../config/client_ip.php', 'client_ip');
        $this->mergeConfigFrom(__DIR__.'/../config/foundation_log.php', 'foundation_log');
        $this->mergeConfigFrom(__DIR__.'/../config/tron_rpc.php', 'tron_rpc');
        $this->mergeConfigFrom(__DIR__.'/../config/foundation_custom.php', 'foundation_custom');
        $this->applyProjectConfigOverrides();

        $this->app->singleton(ClientIpResolver::class, static fn ($app): ClientIpResolver => new TrustedProxyClientIpResolver(
            (array) $app['config']->get('client_ip', []),
            $app->make(FoundationLogger::class)
        ));

        $this->app->singleton(TelegramNotificationSender::class, static fn ($app): TelegramNotificationSender => new TelegramNotificationSender(
            new Client(),
            (array) $app['config']->get('foundation_log.telegram', []),
            $app->make(LogManager::class)
        ));
        $this->app->singleton(TelegramExceptionNotifier::class, static fn ($app): TelegramExceptionNotifier => new TelegramExceptionNotifier(
            $app->make(TelegramNotificationSender::class),
            $app->make(Dispatcher::class),
            (array) $app['config']->get('foundation_log.telegram', []),
            $app->make(CacheRepository::class)
        ));
        $this->app->singleton(
            ExceptionNotifier::class,
            static fn ($app): ExceptionNotifier => $app->make(TelegramExceptionNotifier::class)
        );
        $this->app->singleton(FoundationLogger::class, static fn ($app): FoundationLogger => new FoundationLogger(
            $app->make(LogManager::class),
            $app->make(ExceptionNotifier::class),
            (array) $app['config']->get('foundation_log', [])
        ));

        $this->app->singleton(TronRpcClient::class, static fn ($app): TronRpcClient => new TronRpcClient(
            (array) $app['config']->get('tron_rpc.endpoints', []),
            (string) $app['config']->get('tron_rpc.app_id', 'robots'),
            (string) $app['config']->get('tron_rpc.secret', ''),
            (float) $app['config']->get('tron_rpc.connect_timeout_seconds', 1),
            (float) $app['config']->get('tron_rpc.request_timeout_seconds', 3),
            null,
            $app->make(FoundationLogger::class)
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/foundation_custom.php' => config_path('foundation_custom.php'),
        ], 'foundation-custom-config');
    }

    private function applyProjectConfigOverrides(): void
    {
        $config = $this->app['config'];
        $custom = (array) $config->get('foundation_custom', []);

        foreach (['foundation', 'client_ip', 'foundation_log', 'tron_rpc'] as $key) {
            $overrides = $custom[$key] ?? null;
            if (!is_array($overrides) || $overrides === []) {
                continue;
            }

            $config->set(
                $key,
                ConfigMerger::replaceRecursive((array) $config->get($key, []), $overrides)
            );
        }
    }
}
