<?php

namespace Chencongbao\Foundation;

use Illuminate\Support\ServiceProvider;
use Chencongbao\Foundation\Services\Tron\TronRpcClient;
use Chencongbao\Foundation\Contracts\ClientIpResolver;
use Chencongbao\Foundation\Services\Http\TrustedProxyClientIpResolver;

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
        $this->mergeConfigFrom(__DIR__.'/../config/tron_rpc.php', 'tron_rpc');

        $this->app->singleton(ClientIpResolver::class, static fn ($app): ClientIpResolver => new TrustedProxyClientIpResolver(
            (array) $app['config']->get('client_ip', [])
        ));

        $this->app->singleton(TronRpcClient::class, static fn ($app): TronRpcClient => new TronRpcClient(
            (array) $app['config']->get('tron_rpc.endpoints', []),
            (string) $app['config']->get('tron_rpc.app_id', 'robots'),
            (string) $app['config']->get('tron_rpc.secret', ''),
            (float) $app['config']->get('tron_rpc.connect_timeout_seconds', 1),
            (float) $app['config']->get('tron_rpc.request_timeout_seconds', 3),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/foundation.php' => config_path('foundation.php')], 'foundation-config');
        $this->publishes([__DIR__.'/../config/client_ip.php' => config_path('client_ip.php')], 'foundation-client-ip-config');
        $this->publishes([__DIR__.'/../config/tron_rpc.php' => config_path('tron_rpc.php')], 'foundation-tron-rpc-config');
    }
}
