# Database、Factories 与 Models

- `database/migrations/`：真正属于 Foundation 的数据表迁移。
- `database/factories/`：包模型的测试数据工厂。
- `src/Models/`：与包自身数据表对应的 Eloquent 模型。

只有多个宿主项目都遵循相同表结构时，模型和迁移才放进包里。订单、会员、后台设置等
具体业务模型应留在宿主项目。

模型应明确 `$table`、`$fillable`/`$guarded`、casts 和关联关系，不能引用宿主项目的
`App\Models\...`。
