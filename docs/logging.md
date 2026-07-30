# 模块日志与异常通知

核心默认配置为包内的 `config/foundation_log.php`，支持针对每个功能模块设置日志开关、
最低级别和 Laravel 日志 Channel。项目差异统一写入
`config/foundation_custom.php`。

发布项目差异配置：

```bash
php artisan vendor:publish --tag=foundation-custom-config
```

## 模块配置

```php
return [
    'foundation_log' => [
        'modules' => [
            'payment' => [
                'enabled' => true,
                'path' => storage_path('logs/{date}/foundation/payment.log'),
                'level' => 'warning',
            ],
        ],
    ],
];
```

模块配置了 `path` 时使用独立日志文件；未配置 `path` 时，`channel` 必须在宿主项目
的 `config/logging.php` 中存在。没有单独配置的模块继承 `default`。

默认文件：

```text
storage/logs/2026-07-29/foundation/tron_rpc.log
storage/logs/2026-07-29/foundation/client_ip.log
```

独立开关和路径：

```dotenv
FOUNDATION_LOG_TRON_RPC_ENABLED=true
FOUNDATION_LOG_TRON_RPC_LEVEL=debug
FOUNDATION_LOG_TRON_RPC_PATH=/绝对路径/logs/{date}/foundation/tron_rpc.log

FOUNDATION_LOG_CLIENT_IP_ENABLED=false
FOUNDATION_LOG_CLIENT_IP_LEVEL=info
FOUNDATION_LOG_CLIENT_IP_PATH=/绝对路径/logs/{date}/foundation/client_ip.log
```

模块日志默认关闭，需要哪个模块就显式设置对应的 `FOUNDATION_LOG_*_ENABLED=true`。

`TronRpcClient` 会自动记录请求开始、节点响应、成功、节点故障及最终异常。
`TrustedProxyClientIpResolver` 会自动记录最终解析出的 IP、来源、域名和入口节点。
关闭对应模块后，这些自动日志不会写入。

`{date}` 会在每次写入时替换成当前日期。常驻 Worker 跨过零点后会自动切换到新的日期
目录，文件名保持不变。

## 写日志

推荐依赖注入：

```php
use Chencongbao\Foundation\Services\Logging\FoundationLogger;

private FoundationLogger $logger;

public function __construct(FoundationLogger $logger)
{
    $this->logger = $logger;
}

$this->logger->info('tron_rpc', '开始查询交易', [
    'tx_id' => $txId,
]);
```

也可以使用 Facade：

```php
use Chencongbao\Foundation\Facades\FoundationLog;

FoundationLog::debug('client_ip', '客户端 IP 解析完成', $info);
FoundationLog::warning('payment', '付款匹配到多笔', $context);
FoundationLog::error('tron_rpc', 'RPC 请求失败', $context);
```

## 普通消息：只写本地日志

`message()` 用于记录业务过程、任务完成信息等普通消息，只写本地日志，不发送
Telegram：

```php
use Chencongbao\Foundation\Facades\FoundationLog;

FoundationLog::message('payment', '付款处理时间超过预期', [
    'order_no' => $orderNo,
    'elapsed_ms' => $elapsedMs,
], 'warning');
```

- `enabled=true` 时，消息会按传入级别写入模块日志；
- `enabled=false` 时不写日志；
- 第四个参数是日志级别，默认是 `info`；
- 无论 Telegram 如何配置，`message()` 都不会发送通知。

也可以通过依赖注入调用：

```php
$this->logger->message('payment', '日终对账完成', [
    'total' => $total,
]);
```

## 记录并通知异常

```php
try {
    // 业务代码
} catch (\Throwable $exception) {
    FoundationLog::exception('tron_rpc', $exception, [
        'endpoint' => $endpoint,
        'tx_id' => $txId,
    ]);
}
```

`exception()` 会按模块的 `enabled` 配置决定是否记录 `error` 日志，并始终将异常交给
Telegram 通知器。是否真正发送只由 Telegram 全局配置决定，不再设置模块通知开关。

`debug()`、`info()`、`warning()`、`error()` 和 `message()` 都是纯日志方法，不发送
通知。只有 `exception()` 会进入 Telegram 异常通知流程。

## Telegram 配置

```dotenv
FOUNDATION_TELEGRAM_ENABLED=true
FOUNDATION_TELEGRAM_BOT_TOKEN=123456:bot-token
FOUNDATION_TELEGRAM_CHAT_IDS=-1001234567890,-1009876543210
FOUNDATION_TELEGRAM_TIMEOUT=3

FOUNDATION_TELEGRAM_QUEUE_ENABLED=true
FOUNDATION_TELEGRAM_QUEUE=notice
FOUNDATION_TELEGRAM_QUEUE_TRIES=3
FOUNDATION_TELEGRAM_QUEUE_TIMEOUT=30
FOUNDATION_TELEGRAM_QUEUE_BACKOFF=5
```

真正发送必须同时满足：

1. `FOUNDATION_TELEGRAM_ENABLED=true`；
2. Bot Token 非空；
3. 至少配置一个 Chat ID。

任何一项不满足都不会发送。Telegram 请求失败只返回失败状态，不抛出异常，不影响原
业务。

异常通知模板使用中文字段名：

```text
[应用名称] Foundation 异常通知
运行环境：production
功能模块：tron_rpc
异常类型：RuntimeException
异常消息：RPC 请求失败
错误代码：0
发生时间：2026-07-30T09:00:00+08:00
上下文：{"node":"tronweb1"}
```

异常类名、模块名、原始异常消息和上下文数据不会翻译，确保诊断信息保持准确。

## 相同异常去重

Telegram 异常默认去重 300 秒。同一模块、异常类和异常消息生成同一个 SHA-256
指纹，5 分钟内只发送第一次：

```text
模块 + 异常类 + 异常消息
```

Context、异常文件和行号不参与指纹。本地日志不受去重影响，每次异常仍会完整记录。
去重使用 Laravel 默认 Cache；多台服务器需要使用 Redis 等共享缓存，才能实现跨服务器
去重。缓存不可用时会跳过去重并继续通知，不影响原业务。

如需调整时间，在 `config/foundation_custom.php` 中覆盖：

```php
return [
    'foundation_log' => [
        'telegram' => [
            'deduplicate_seconds' => 600,
        ],
    ],
];
```

设为 `0` 可以关闭异常去重。

项目可以指定额外参与指纹的 Context 字段。例如相同 TRON 异常需要按照节点分别通知：

```php
return [
    'foundation_log' => [
        'telegram' => [
            'deduplicate_context_keys' => [
                'node',
            ],
        ],
    ],
];
```

项目调用时必须把字段传入 `exception()` 的 Context：

```php
FoundationLog::exception('tron_rpc', $exception, [
    'node' => $node,
    'pool' => 'fullnode',
]);
```

此时 `tronweb1` 和 `tronweb2` 会分别通知；同一个节点的相同异常在 5 分钟内仍只通知
一次。字段名完全由项目配置，Foundation 不依赖 `node` 等业务含义。支持点号路径：

```php
'deduplicate_context_keys' => [
    'server.node',
    'provider.pool',
],
```

默认值为空数组，因此未配置该功能的项目仍只使用模块、异常类和异常消息生成指纹。

必须每次通知的异常可以加入去重排除名单。异常类由项目定义，Foundation 只按类名
判断，不依赖项目业务：

```php
return [
    'foundation_log' => [
        'telegram' => [
            'deduplicate_exclude_exceptions' => [
                \App\Exceptions\CriticalTronException::class,
                \App\Exceptions\SecurityAlertException::class,
            ],
        ],
    ],
];
```

命中名单的异常完全跳过缓存去重，每次都会投递通知。使用 `is_a()` 判断，因此配置父类
时其子类也会命中。建议为必须实时通知的情况建立明确的自定义异常类，不要直接排除
`RuntimeException` 等使用范围很大的基础异常。

## Telegram 异步队列

Telegram 通知默认通过 Laravel Queue 异步投递。业务请求只负责写本地日志和提交
`SendTelegramNotification` Job，不会等待 Telegram HTTP 请求完成。

要实现真正异步，宿主项目的队列连接不能是 `sync`，并且必须启动对应队列 Worker：

```dotenv
QUEUE_CONNECTION=redis
```

```bash
php artisan queue:work --queue=notice
```

Foundation 默认跟随宿主项目 `config/queue.php` 的 `default` 连接，也就是通常由
`QUEUE_CONNECTION` 决定，不需要设置 `FOUNDATION_TELEGRAM_QUEUE_CONNECTION`。
只有通知任务必须使用与项目默认队列不同的连接时，才进行单独覆盖：

```dotenv
FOUNDATION_TELEGRAM_QUEUE_CONNECTION=redis
```

Foundation 默认将通知 Job 投递到 `notice` 队列：

```dotenv
FOUNDATION_TELEGRAM_QUEUE=notice
```

如果配置其他名称，则使用配置的队列：

```dotenv
FOUNDATION_TELEGRAM_QUEUE=telegram-alerts
```

Worker 必须监听最终使用的队列名：

```bash
php artisan queue:work --queue=telegram-alerts
```

如果使用 Supervisor，请让 Supervisor 持续运行以上 Worker。发布新代码或修改包代码
后执行：

```bash
php artisan queue:restart
```

发送失败时 Job 会抛出异常，由 Laravel Queue 按 `TRIES` 和 `BACKOFF` 配置重试。
Job 只序列化通知文本，不会把 Telegram Bot Token 和 Chat ID 写入队列 Payload。

如需本地调试时恢复同步发送：

```dotenv
FOUNDATION_TELEGRAM_QUEUE_ENABLED=false
```

注意：仅设置 `FOUNDATION_TELEGRAM_QUEUE_ENABLED=true` 但项目
`QUEUE_CONNECTION=sync` 时，Laravel 仍会在当前进程立即执行 Job，不属于真正异步。

模块 `enabled` 只控制文件日志，不控制异常通知。所有 `exception()` 异常都会交给
Telegram 通知器；`message()` 只写本地日志。Telegram 全局 `enabled` 默认开启，
未配置 Bot Token 或 Chat ID 时异常不会入队，也不会发出请求。

## 敏感字段

配置中的 `sensitive_keys` 用于递归脱敏上下文。默认会处理 Token、Secret、Password、
Private Key、Signature 和 Authorization 等键：

```php
[
    'token' => '[REDACTED]',
    'request' => [
        'authorization' => '[REDACTED]',
    ],
]
```

不要把密钥直接写入异常 Message；字段脱敏只能处理结构化 Context。
