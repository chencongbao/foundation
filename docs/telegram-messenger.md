# 自定义 Telegram 消息

`TelegramMessenger` 是项目主动发送 Telegram 消息的独立服务，不属于异常通知流程，
也不会改变 `FoundationLog::message()` 只写本地日志的行为。

支持：

- 使用全局默认 Bot Token 和 Chat ID；
- 每次发送临时覆盖 Bot Token；
- 指定单个、多个或逗号分隔的 Chat ID；
- 回复指定的 Telegram 消息；
- 携带 URL 或回调按钮发送消息；
- 发送自动转义的纯文本；
- 发送调用方构造的 Telegram HTML；
- 发送带语言标签和复制按钮的代码块；
- 发送自动格式化的 JSON，并组合应用名称和自定义标题；
- 设置、删除和查询 Telegram Webhook；
- 模块开启时成功操作写入 `telegram.log`，失败详情始终写入
  `telegram_failure.log`，Bot Token 自动脱敏。

## 依赖注入

```php
use Chencongbao\Foundation\Services\Notification\TelegramMessenger;

final class LoginAlertService
{
    public function __construct(private TelegramMessenger $telegram)
    {
    }

    public function send(string $username, string $ip): bool
    {
        $content = implode("\n", [
            '=====‼️‼️📢系统用户登录异常报警🆘‼️‼️=====',
            '系统端：商户后台',
            '用户名：'.$username,
            '登录IP：'.$ip,
            '登录时间：'.now('Asia/Shanghai')->format('Y-m-d H:i:s'),
        ]);

        $html = $this->telegram->format($content, 'code', [
            'language' => 'sgpay',
        ]);

        return $this->telegram
            ->withToken(config('services.login_alert.bot_token'))
            ->to(config('services.login_alert.chat_ids'))
            ->sendHtml($html);
    }
}
```

`format()` 负责显示格式，`sendHtml()` 负责发送。代码块可通过 `options` 指定语言和标题：

```php
$html = $telegram->format($content, 'code', [
    'language' => 'sgpay',
    'title' => '[sgpay] 登录异常',
]);

$telegram
    ->withToken($token)
    ->to(['-1001234567890', '123456789'])
    ->sendHtml($html);
```

## Facade

```php
use Chencongbao\Foundation\Facades\FoundationTelegram;

FoundationTelegram::withToken($token)
    ->to($chatId)
    ->sendText('任务执行完成');

FoundationTelegram::withToken($token)
    ->to([$chatId1, $chatId2])
    ->sendHtml('<b>系统告警</b>');

FoundationTelegram::to($chatId)
    ->replyTo($messageId)
    ->sendText('已收到，正在处理。');

FoundationTelegram::to($chatId)
    ->withButtons([
        ['text' => '查看详情', 'url' => 'https://example.com/orders/9527'],
        ['text' => '确认处理', 'callback_data' => 'order:9527:confirm'],
    ])
    ->sendText('发现一笔异常订单');

$html = FoundationTelegram::format($data, 'json', [
    'title' => '接口异常',
]);

FoundationTelegram::withToken($token)
    ->to('-1001,-1002')
    ->sendHtml($html);

FoundationTelegram::withToken($token)
    ->to('-1001,-1002')
    ->withTitle('接口异常')
    ->sendJson($data);

FoundationTelegram::withTitle('接口异常')
    ->withoutAppName()
    ->sendJson($data);
```

## Webhook 管理

后台保存 Bot Token 时，可以继续通过 `withToken()` 按次覆盖：

```php
FoundationTelegram::withToken($botToken)->setWebhook(
    'https://example.com/telegram/webhook',
    [
        'max_connections' => 20,
        'allowed_updates' => [
            'message',
            'callback_query',
        ],
        'drop_pending_updates' => true,
        'secret_token' => 'webhook_secret-123',
    ]
);
```

删除 Webhook：

```php
FoundationTelegram::withToken($botToken)->removeWebhook();

// 同时清除 Telegram 当前等待投递的更新
FoundationTelegram::withToken($botToken)->removeWebhook(true);
```

查询 Webhook 状态：

```php
$info = FoundationTelegram::withToken($botToken)->getWebhookInfo();
```

`setWebhook()` 支持 `ip_address`、`max_connections`、`allowed_updates`、
`drop_pending_updates` 和 `secret_token`。当前不处理自签名证书文件上传。
Webhook 管理为同步 API 调用；`modules.telegram` 开启时成功操作写入
`telegram.log`，失败详情始终写入 `telegram_failure.log`。调用失败返回 `false`
或空数组。操作日志不会记录 Webhook URL 或 `secret_token` 的值。

## 参数规则

方法签名：

```php
withToken(string $botToken): TelegramMessenger
to(array|string $chatIds): TelegramMessenger
replyTo(int $messageId, bool $allowSendingWithoutReply = false): TelegramMessenger
withButtons(array $buttons): TelegramMessenger
withTitle(string $title): TelegramMessenger
withoutAppName(bool $without = true): TelegramMessenger
format(mixed $content, string $format = 'text', array $options = []): string
sendText(string $text): bool
sendHtml(string $html): bool
sendJson(mixed $data): bool
setWebhook(string $url, array $options = []): bool
removeWebhook(bool $dropPendingUpdates = false): bool
getWebhookInfo(): array
```

- 不调用 `withToken()` 时使用 `FOUNDATION_TELEGRAM_BOT_TOKEN`；
- 不调用 `to()` 时使用 `FOUNDATION_TELEGRAM_CHAT_IDS`；
- `to()` 可以接收单个字符串、逗号分隔字符串或数组；
- `replyTo($messageId)` 回复当前 Chat 中的指定消息；
- `replyTo($messageId, true)` 在原消息不存在时仍发送为普通消息；
- `withButtons()` 接收一维数组时显示为一行，接收二维数组时按数组分行；
- 按钮必须且只能配置 `url` 或 `callback_data`；`callback_data` 限制为 1 到 64 字节；
- `withTitle('接口异常')` 设置 JSON 消息标题；
- `withoutAppName()` 隐藏 JSON 标题中的 `[APP_NAME]`；
- 所有 `with...()`、`to()`、`replyTo()` 方法均返回新实例，不修改容器单例，适用于 Queue Worker 和 Octane；
- `format()` 支持 `text`、`html`、`code`、`json` 四种格式，返回可交给 `sendHtml()` 的内容；
- `format($content, 'code', ['language' => 'sgpay', 'title' => '登录异常'])` 生成带标题的代码块；
- `format($data, 'json', ['title' => '接口异常'])` 格式化 JSON，并自动组合应用名称；
- `sendText()` 会自动进行 HTML 转义；
- `sendHtml()` 不转义内容，只能传入调用方确认安全的 Telegram HTML；
- `sendJson()` 接受数组、对象或合法 JSON 字符串，自动缩进且中文不转义；
- `withTitle('接口异常')->sendJson($data)` 的标题为 `[APP_NAME] 接口异常`；
- `sendJson($data)` 的标题只有 `[APP_NAME]`；
- 任意一个接收方发送失败时返回 `false`；
- `FOUNDATION_TELEGRAM_ENABLED=false` 时不会发送。

当前自定义凭据发送为同步方式，避免把临时 Bot Token 序列化进 Queue Payload。异常通知
仍然按 Foundation 配置使用异步 `notice` 队列。
