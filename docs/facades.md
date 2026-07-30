# Facades

`src/Facades/` 放 Laravel Facade，为高频服务提供简洁静态入口。

每个 Facade 必须：

- 对应一个容器绑定；
- 在 PHPDoc 中声明常用方法；
- 通过 `getFacadeAccessor()` 返回类名或绑定键；
- 同时保留构造函数依赖注入的使用方式。

核心业务类优先依赖注入，Facade 更适合控制器、命令等 Laravel 边界层。

当前提供：

- `FoundationLog`：模块日志和异常日志；
- `TronRpc`：TRON RPC 客户端；
- `FoundationTelegram`：发送自定义文本、HTML 和代码块 Telegram 消息。
