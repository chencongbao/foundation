# DTOs

`src/DTOs/` 放跨层传递的结构化数据，替代键名不明确的关联数组。

PHP 8.0 使用 `final class` 和类型属性，通过构造函数完成赋值：

```php
final class PageResult
{
    public array $items;
    public int $total;

    public function __construct(
        array $items,
        int $total,
    ) {
        $this->items = $items;
        $this->total = $total;
    }
}
```

DTO 只表达数据，不负责数据库查询、缓存和远程调用。需要保证自身有效性的业务值，应
使用 ValueObject。
