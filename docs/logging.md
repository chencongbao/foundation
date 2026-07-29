# 模块日志与异常通知

配置文件为 `config/foundation_log.php`，支持针对每个功能模块设置日志开关、最低级别、
Laravel 日志 Channel 和异常通知开关。

发布配置：

```bash
php artisan vendor:publish --tag=foundation-log-config
```

## 模块配置

```php
'default' => [
    'enabled' => true,
    'channel' => 'stack',
    'level' => 'debug',
    'notify' => false,
],

'modules' => [
    'tron_rpc' => [
        'enabled' => true,
        'channel' => 'daily',
        'level' => 'info',
        'notify' => true,
    ],
    'payment' => [
        'enabled' => true,
        'channel' => 'daily',
        'level' => 'warning',
        'notify' => false,
    ],
],
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
FOUNDATION_LOG_TRON_RPC_NOTIFY=false

FOUNDATION_LOG_CLIENT_IP_ENABLED=false
FOUNDATION_LOG_CLIENT_IP_LEVEL=info
FOUNDATION_LOG_CLIENT_IP_PATH=/绝对路径/logs/{date}/foundation/client_ip.log
FOUNDATION_LOG_CLIENT_IP_NOTIFY=false
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

`exception()` 会先按模块配置记录 `error` 日志；只有该模块 `notify=true` 时才尝试异常
通知。

## Telegram 配置

```dotenv
FOUNDATION_TELEGRAM_ENABLED=true
FOUNDATION_TELEGRAM_BOT_TOKEN=123456:bot-token
FOUNDATION_TELEGRAM_CHAT_IDS=-1001234567890,-1009876543210
FOUNDATION_TELEGRAM_MESSAGE_THREAD_ID=
FOUNDATION_TELEGRAM_TIMEOUT=3
```

真正发送必须同时满足：

1. 模块 `notify=true`；
2. `FOUNDATION_TELEGRAM_ENABLED=true`；
3. Bot Token 非空；
4. 至少配置一个 Chat ID。

任何一项不满足都不会发送。Telegram 请求失败只返回失败状态，不抛出异常，不影响原
业务。

日志和通知开关相互独立：模块 `enabled` 只控制文件日志，模块 `notify` 只控制异常
通知。TRON RPC 的 `notify` 和 Telegram 全局 `enabled` 默认开启；未配置 Bot Token
或 Chat ID 时仍不会发出请求。

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
