# Tests

测试位于 `tests/`：

- `Unit/`：纯类、值对象和算法测试；
- `Feature/`：Provider、容器绑定、配置、路由和 Laravel 集成测试；
- `TestCase.php`：基于 Orchestra Testbench 的包测试基类。

新增功能至少覆盖正常路径和关键异常路径。外部 API 使用 Mock Client，不在自动测试中
访问真实服务。

安装开发依赖后运行：

```bash
composer install
vendor/bin/phpunit
```
