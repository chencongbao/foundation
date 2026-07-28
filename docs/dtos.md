# DTOs

`src/DTOs/` 放跨层传递的结构化数据，替代键名不明确的关联数组。

DTO 建议使用 `final readonly class`，通过构造函数声明字段和类型：

```php
final readonly class PageResult
{
    public function __construct(
        public array $items,
        public int $total,
    ) {
    }
}
```

DTO 只表达数据，不负责数据库查询、缓存和远程调用。需要保证自身有效性的业务值，应
使用 ValueObject。
