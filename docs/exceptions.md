# Exceptions

`src/Exceptions/` 放包内自定义异常。

异常应表达调用方可以处理的错误边界，例如协议错误、鉴权错误和外部服务不可用。
需要重试判断、HTTP 状态或远端错误码时，使用只读字段和明确的访问方法。

异常信息不能包含密钥、Token、完整签名或其他敏感数据。目前 TRON RPC 使用
`TronRpcException` 保存 RPC code、HTTP 状态、data 和 `retryable` 状态。
