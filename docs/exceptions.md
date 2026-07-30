# Exceptions

`src/Exceptions/` 放包内自定义异常。

异常应表达调用方可以处理的错误边界，例如协议错误、鉴权错误和外部服务不可用。
需要重试判断、HTTP 状态或远端错误码时，使用只读字段和明确的访问方法。

异常信息不能包含密钥、Token、完整签名或其他敏感数据。目前 TRON RPC 使用
`TronRpcException` 保存 RPC code、HTTP 状态、data 和 `retryable` 状态。

`TelegramTransportException` 表示 Telegram Queue Job 发送失败。该异常仍然会抛给
Laravel Queue，因此正常参与重试并可进入 `failed_jobs`；但其 `report()` 返回
`true`，表示 Foundation 已将失败详情写入 `telegram.log`，Laravel 不应再把它交给
全局异常上报入口，避免“通知失败后再次发送通知”的递归循环。
