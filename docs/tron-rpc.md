# TRON RPC

TRON RPC 实现在 `src/Services/Tron/TronRpcClient.php`，异常位于
`src/Exceptions/TronRpcException.php`，Laravel 静态入口位于
`src/Facades/TronRpc.php`。

配置文件是 `config/tron_rpc.php`，支持多个 Endpoint、App ID、HMAC Secret、连接超时
和请求超时。

推荐通过构造函数注入：

```php
use Chencongbao\Foundation\Services\Tron\TronRpcClient;

private TronRpcClient $tronRpc;

public function __construct(TronRpcClient $tronRpc)
{
    $this->tronRpc = $tronRpc;
}
```

控制器或命令中也可以使用 `TronRpc` Facade。客户端只在连接失败、超时或 HTTP 5xx
时切换节点。捕获 `TronRpcException` 后，通过 `retryable()` 判断是否应由队列稍后
重试。日志中禁止记录 HMAC Secret。
