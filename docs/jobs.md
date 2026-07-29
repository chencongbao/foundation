# Jobs

`src/Jobs/` 放可跨项目复用的 Laravel 队列任务。Job 只保存完成任务所需的最小数据，
外部客户端和密钥应在 Worker 执行时通过 Laravel 容器及配置注入，不写入队列
Payload。

## Telegram 通知任务

`SendTelegramNotification` 负责在 Queue Worker 中调用 Telegram：

- 异常通知和普通消息通知共用该 Job；
- 默认投递到 `foundation-notifications` 队列；
- 默认最多尝试 3 次，失败后等待 5 秒重试；
- Job 只保存已经格式化和脱敏的通知文本；
- Bot Token、Chat ID 和 HTTP Client 由 Worker 运行时解析。

业务代码不应直接创建这个 Job，应通过以下入口调用：

```php
FoundationLog::exception('tron_rpc', $exception, $context);
FoundationLog::message('payment', '付款处理完成', $context);
```

完整队列配置和 Worker 命令见 [模块日志与异常通知](logging.md)。
