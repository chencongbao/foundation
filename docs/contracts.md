# Contracts

`src/Contracts/` 放接口和能力约定，用于隔离业务调用方与具体实现。

适合放入：

- 仓储、客户端、序列化器等稳定接口；
- 可能存在多个实现的服务；
- 需要在测试中替换实现的外部依赖。

接口使用职责名称，例如 `PaymentGateway`、`SettingRepository`，不要使用
`IService` 等无业务含义名称。实现类放在 `Services/`，并在 Provider 中完成绑定。

当前通知接口：

- `ExceptionNotifier`：发送异常通知；

默认实现通过 Telegram 异步发送异常。业务代码通常使用 `FoundationLogger` 或
`FoundationLog`，只有替换异常通知渠道或单独测试通知发送时才直接依赖该接口。
