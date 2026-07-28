# Functions

`src/Functions/` 只放少量、高频、逻辑简单的全局函数：

- `helpers.php`：无法归入其他类别的函数。
- `string.php`：字符串函数。
- `array.php`：数组函数。
- `request.php`：请求相关函数。

所有函数必须使用 `bob_` 前缀、添加参数和返回类型，并由 `function_exists()` 包裹。
数据库查询、缓存、远程请求和复杂分支应改为 `Support` 或 `Services` 类。

这些文件通过 Composer `autoload.files` 自动加载，新增文件后需要执行
`composer dump-autoload`。
