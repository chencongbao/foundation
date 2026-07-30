# Telegram Webhook 处理

`TelegramWebhookHandler` 封装所有项目都会重复实现的 Webhook 基础能力：

- 解析 Telegram JSON Update；
- 校验 `X-Telegram-Bot-Api-Secret-Token`；
- 使用 Laravel Cache 对 `update_id` 原子去重；
- 判断命令消息和 Callback Query；
- 统一向 Telegram 返回 `200 ok`；
- 捕获业务异常并交给项目提供的异常回调；
- Bot Token 脱敏和异常消息清理。

业务权限、命令处理器、模型和通知服务继续留在宿主项目。

## 配置

```dotenv
FOUNDATION_TELEGRAM_WEBHOOK_SECRET_TOKEN=webhook_secret-123
FOUNDATION_TELEGRAM_WEBHOOK_DEDUPLICATE_SECONDS=600
```

设置 Webhook 时使用相同的 Secret Token：

```php
FoundationTelegram::withToken($botToken)->setWebhook($url, [
    'secret_token' => config('foundation_log.telegram.webhook.secret_token'),
    'allowed_updates' => [
        'message',
        'callback_query',
    ],
]);
```

Secret Token 保存在后台时，可以按次覆盖：

```php
return FoundationTelegramWebhook::withSecretToken($secretToken)->handle(
    $request,
    $handler,
    $onException
);
```

## 控制器调用

以下示例保留项目自己的 Telegram SDK、权限和命令业务，只移除重复的解析、去重、
异常捕获和 Token 脱敏代码：

```php
<?php

namespace App\Http\Controllers;

use Throwable;
use Illuminate\Http\Request;
use App\Extendtions\Telegram\Message;
use App\Services\Telegram\TelegramManagerService;
use App\Services\SystemNotice\SystemNoticeService;
use App\Services\Telegram\TelegramInstanceService;
use Chencongbao\Foundation\DTOs\TelegramWebhookUpdate;
use Chencongbao\Foundation\Facades\FoundationTelegramWebhook;

final class TelegramController extends Controller
{
    public function webhook(Request $request)
    {
        return FoundationTelegramWebhook::handle(
            $request,
            function (TelegramWebhookUpdate $update): void {
                $payload = $update->all();
                $isCommand = $update->isCommand();
                $telegram = app(TelegramInstanceService::class)->excute(false, $isCommand);

                if (!$isCommand) {
                    (new Message($telegram))->init($payload);

                    return;
                }

                $manager = app(TelegramManagerService::class);
                $message = $update->message();
                $chat = $update->chat();
                $chatId = (int) ($update->chatId() ?? 0);
                $text = strtolower(trim((string) $update->text()));

                if ($manager->isPrivateChat($chat) && !$manager->isManagerMessage($message)) {
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => '您暂无权限使用私聊机器人命令，请联系管理员开通权限。',
                    ]);

                    return;
                }

                if (str_starts_with($text, '/success_rate')) {
                    (new Message($telegram))->init($payload);

                    return;
                }

                if (
                    $chatId < 0
                    && (
                        str_starts_with($text, '/channel_rate')
                        || str_starts_with($text, '/channel_fixed_rate')
                    )
                ) {
                    return;
                }

                $telegram->commandsHandler(true);
            },
            function (Throwable $exception, TelegramWebhookUpdate $update): void {
                $token = (string) config('telegram.telegram_bot_token');
                app(SystemNoticeService::class)->warning('system_manual_notice', [
                    'message' => FoundationTelegramWebhook::sanitizeError(
                        $exception->getMessage(),
                        $token
                    ),
                    'line' => $exception->getLine(),
                    'file' => $exception->getFile(),
                    'updates' => $update->all(),
                    'telegram_bot_token_configured' => $token !== '',
                    'telegram_bot_token_masked' => FoundationTelegramWebhook::maskToken($token),
                ]);
            }
        );
    }
}
```

## Update DTO

回调接收 `TelegramWebhookUpdate`，常用方法：

```php
$update->all();
$update->raw();
$update->updateId();
$update->message();
$update->callbackQuery();
$update->chat();
$update->chatId();
$update->text();
$update->isCommand();
$update->command();
$update->isCallbackQuery();
```

`command()` 会将 `/SUCCESS_RATE@my_bot params` 标准化为 `/success_rate`。

## 去重规则

默认使用以下缓存键保存 600 秒：

```text
foundation:telegram:webhook:{update_id}
```

多台服务器必须使用共享 Redis Cache 才能跨节点去重。缓存不可用时会放行当前 Update，
避免整个 Webhook 停止工作。将去重时间配置为 `0` 可以关闭去重。

Secret Token 配置为空时保持兼容，不校验请求头；生产环境建议必须配置。
