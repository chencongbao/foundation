# Foundation

Laravel 项目共享基础包。这里只收录跨项目可复用、接口稳定、且不依赖宿主项目
`App\...` 代码的能力。

各模块的用途、边界和示例见 [Foundation 文档索引](docs/README.md)。

支持 Laravel 9.19+、10、11、12 和 13。包本身保持 PHP 8.0.2 语法兼容；
安装 Laravel 10 或更高版本时，PHP 版本还需要满足对应 Laravel 版本的要求。新的
Laravel 主版本发布后，应在兼容测试通过后再追加版本约束，避免未经验证地自动安装。

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
├── Jobs/            可跨项目复用的 Laravel 队列任务
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

核心配置由 Service Provider 自动加载，不发布到宿主项目。项目只发布并维护
`config/foundation_custom.php` 差异配置，包升级后未覆盖的默认值会自动更新：

```bash
php artisan vendor:publish --tag=foundation-custom-config
```

Foundation 的 Telegram 异常通知默认投递到 Laravel Queue。生产环境需要
使用非 `sync` 队列连接并运行 Queue Worker；未配置队列名时使用 `notice` 队列，
配置 `FOUNDATION_TELEGRAM_QUEUE` 后使用指定队列。详细配置见
[模块日志与异常通知](docs/logging.md)。

项目主动发送自定义 Telegram 消息时使用 `TelegramMessenger` 或
`FoundationTelegram` Facade，可按次指定 Bot Token、一个或多个 Chat ID，并发送
纯文本、HTML、格式化 JSON、带复制按钮的代码块或网络/本地图片。详细用法见
[自定义 Telegram 消息](docs/telegram-messenger.md)。

Telegram Webhook 的 JSON 解析、Secret Token 校验、`update_id` 去重和统一异常回调，
使用 `FoundationTelegramWebhook`。宿主项目只保留命令、权限和消息业务，详见
[Telegram Webhook 处理](docs/telegram-webhook.md)。

## TRON 基础工具

不需要调用节点的地址校验、地址格式转换和数量换算使用 `Support\Tron`：

```php
use Chencongbao\Foundation\Support\Tron;

$valid = Tron::isValidAddress($address);
$validHash = Tron::isHash($hash);
$hexAddress = Tron::toHexAddress($address);
$base58Address = Tron::toBase58Address($hexAddress);
$sun = Tron::trxToSun('1.25');
$trx = Tron::sunToTrx($sun);
```

完整方法见 [Support 通用工具](docs/support.md)。

## 密码协议工具

支付接口常用的 AES、RSA 和 3DES 本地处理能力位于 `Support\Crypto`：

```php
use Chencongbao\Foundation\Support\Crypto\Aes;
use Chencongbao\Foundation\Support\Crypto\Rsa;
use Chencongbao\Foundation\Support\Crypto\TripleDes;

$ciphertext = Aes::encryptCbcBase64($plainText, $key, $iv, 128);
$signature = Rsa::signBase64($data, $privateKey, OPENSSL_ALGO_SHA256);
$encrypted = TripleDes::encryptBase64($plainText, $key, $iv);
```

完整方法和协议注意事项见 [AES、RSA 与 3DES 工具](docs/crypto.md)。

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

private TronRpcClient $tronRpc;

public function __construct(TronRpcClient $tronRpc)
{
    $this->tronRpc = $tronRpc;
}
```

客户端只会在连接失败、超时或 HTTP 5xx 时切换节点。捕获
`Chencongbao\Foundation\Exceptions\TronRpcException` 后可通过 `retryable()` 判断是否
应该进入队列延迟重试。鉴权失败、参数错误、HTTP 429 和正常的 `found=false` 不会切换。
