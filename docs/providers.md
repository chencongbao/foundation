# Providers

根服务提供者是 `src/FoundationServiceProvider.php`，负责包的统一入口：

- `register()`：合并配置、绑定接口与服务、注册单例。
- `boot()`：发布配置、加载路由、迁移和语言资源。

某个模块注册逻辑较多时，在 `src/Providers/` 建立独立 Provider，再由根 Provider 注册。
Provider 不应该执行数据库查询、远程请求或其他耗时业务。

包已通过 `composer.json` 的 Laravel Package Discovery 自动发现，无需宿主项目手动注册。
