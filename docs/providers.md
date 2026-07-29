# Providers

根服务提供者是 `src/FoundationServiceProvider.php`，负责包的统一入口：

- `register()`：加载核心默认配置、递归合并项目差异配置、绑定接口与服务、注册单例。
- `boot()`：只发布 `foundation_custom.php` 差异配置模板。

某个模块注册逻辑较多时，在 `src/Providers/` 建立独立 Provider，再由根 Provider 注册。
Provider 不应该执行数据库查询、远程请求或其他耗时业务。

包已通过 `composer.json` 的 Laravel Package Discovery 自动发现，无需宿主项目手动注册。

核心配置不发布。Provider 先加载 `foundation`、`client_ip`、`foundation_log` 和
`tron_rpc` 默认值，再使用 `foundation_custom` 中对应的顶级键递归覆盖。
