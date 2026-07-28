# Foundation

Laravel 项目共享基础包。这里只收录跨项目可复用、接口稳定、且不依赖宿主项目
`App\...` 代码的能力。

## 目录约定

项目已建立以下标准目录；暂时没有实现的目录使用 `.gitkeep` 保留：

```text
src/
├── Contracts/       接口和能力约定
├── DTOs/            跨层数据对象
├── Enums/           固定状态枚举
├── Exceptions/      包异常
├── Facades/         Laravel Facade
├── Functions/       少量高频全局函数
├── Http/            通用中间件、Request
├── Providers/       拆分后的服务注册器
├── Rules/           通用验证规则
├── Services/        外部服务或依赖 Laravel 的功能
├── Support/         无状态通用类
├── Traits/          单一、明确的复用能力
└── ValueObjects/    带校验和行为的值对象
```

收录代码前确认：

1. 至少会被两个项目或模块复用；
2. 不包含当前业务的模型、表名和后台框架；
3. 不依赖宿主项目的 `App\...` 类；
4. 复杂逻辑使用类，只把简单、高频入口做成全局函数。

类统一使用 `Chencongbao\Foundation\` 命名空间。全局函数必须带项目约定前缀，并用
`function_exists` 防止冲突。

## 本地开发

主项目通过 Composer 使用此包。修改类或新增文件后执行：

```bash
composer dump-autoload
php artisan optimize:clear
```

配置文件由 Service Provider 合并；需要修改时发布对应配置，而不是在业务代码中直接
读取环境变量。

## TRON 内网 RPC

包会通过 Laravel Package Discovery 自动注册 `TronRpcClient` 和 `TronRpc` Facade。
robots 项目配置：

```dotenv
TRON_RPC_ENDPOINTS=10.0.1.11:9600,10.0.1.12:9600,10.0.1.13:9600,10.0.1.14:9600
TRON_RPC_APP_ID=robots
TRON_RPC_SECRET=与服务端TRON_RPC_ROBOTS_SECRET相同的至少32字符密钥
TRON_RPC_CONNECT_TIMEOUT=1
TRON_RPC_REQUEST_TIMEOUT=3
```

常用调用：

```php
use Chencongbao\Foundation\Facades\TronRpc;

$transaction = TronRpc::transaction($txId);
$chainTransaction = TronRpc::chainTransaction($txId);
$transactions = TronRpc::transactionsByAddress($address, 'incoming', 1, 20);
$payment = TronRpc::findPayment([
    'to_address' => $toAddress,
    'from_address' => $fromAddress,
    'amount_raw' => $amountRaw,
    'type' => 1,
    'start_time_ms' => $startTimeMs,
    'end_time_ms' => $endTimeMs,
]);
$balance = TronRpc::addressBalance($address);
```

也可以通过依赖注入使用：

```php
use Chencongbao\Foundation\Services\Tron\TronRpcClient;

public function __construct(private readonly TronRpcClient $tronRpc)
{
}
```

客户端只会在连接失败、超时或 HTTP 5xx 时切换节点。捕获
`Chencongbao\Foundation\Exceptions\TronRpcException` 后可通过 `retryable()` 判断是否
应该进入队列延迟重试。鉴权失败、参数错误、HTTP 429 和正常的 `found=false` 不会切换。
