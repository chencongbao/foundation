# Jobs

`src/Jobs/` 放可跨项目复用的 Laravel 队列任务。Job 只保存完成任务所需的最小数据，
外部客户端和密钥应在 Worker 执行时通过 Laravel 容器及配置注入，不写入队列
Payload。

## Telegram 通知任务

`SendTelegramNotification` 负责在 Queue Worker 中调用 Telegram：

- 只处理 `FoundationLog::exception()` 产生的异常通知；
- 未配置队列名时投递到 `notice` 队列；
- 默认最多尝试 3 次，失败后等待 5 秒重试；
- 相同异常默认在入队前去重 300 秒；
- Job 只保存已经格式化和脱敏的通知文本；
- Bot Token、Chat ID 和 HTTP Client 由 Worker 运行时解析。

业务代码不应直接创建这个 Job，应通过以下入口调用：

```php
FoundationLog::exception('tron_rpc', $exception, $context);
```

Job 发送失败时抛出专用的 `TelegramTransportException`，让 Laravel Queue 正常重试并
在最终失败后写入 `failed_jobs`。异常的 `report()` 会阻止 Laravel 全局异常 Handler
再次发送 Telegram；Foundation 已经将传输失败详情写入 `telegram.log`。宿主项目不再
需要通过 vendor 文件路径识别这个异常。

完整队列配置和 Worker 命令见 [模块日志与异常通知](logging.md)。
