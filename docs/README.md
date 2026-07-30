# Foundation 文档

## 基础设施

- [配置](config.md)
- [服务提供者](providers.md)
- [数据库与工厂](database.md)
- [Models 模型](models.md)
- [路由](routes.md)
- [多语言资源](resources-lang.md)
- [测试](testing.md)

## 代码模块

- [Contracts 接口约定](contracts.md)
- [DTOs 数据对象](dtos.md)
- [Enums 枚举](enums.md)
- [Exceptions 异常](exceptions.md)
- [Facades 门面](facades.md)
- [Functions 全局函数](functions.md)
- [HTTP 层](http.md)
- [Jobs 队列任务](jobs.md)
- [客户端 IP 解析](client-ip.md)
- [模块日志与异常通知](logging.md)
- [自定义 Telegram 消息](telegram-messenger.md)
- [Rules 验证规则](rules.md)
- [Services 服务](services.md)
- [TRON RPC 服务](tron-rpc.md)
- [Support 通用工具](support.md)
- [Traits 复用能力](traits.md)
- [ValueObjects 值对象](value-objects.md)

## 开发原则

新代码只有在跨项目复用、接口相对稳定、不依赖宿主项目 `App\...` 代码时，才放入
Foundation。具体业务流程、业务数据表和后台页面留在对应项目中。
