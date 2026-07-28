# Models

`src/Models/` 放与 Foundation 自身数据表对应的 Eloquent 模型。

模型应明确 `$table`、`$fillable`/`$guarded`、casts 和关联关系，不能引用宿主项目的
`App\Models\...`。

如果一个模型只服务于单个项目的业务流程，即使多个类都在使用它，也应保留在宿主
项目，而不是为了方便移动到 Foundation。
