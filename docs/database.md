# Database 与 Factories

- `database/migrations/`：真正属于 Foundation 的数据表迁移。
- `database/factories/`：包模型使用的测试数据工厂。

只有多个宿主项目都遵循相同表结构时，迁移才放进包里。订单、会员、后台设置等具体
业务数据表应留在宿主项目。

迁移必须支持回滚，表名应避免与宿主项目冲突。包需要提供迁移时，由 Service Provider
调用 `loadMigrationsFrom()`；允许用户修改的迁移则通过发布标签复制到宿主项目。
