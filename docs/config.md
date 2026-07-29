# 配置

配置文件位于 `config/`。

- `foundation.php`：包级通用配置。
- `client_ip.php`：客户端 IP、CDN 域名和可信节点配置。
- `foundation_log.php`：模块日志和 Telegram 异常通知配置。
- 独立服务使用单独配置，例如 `tron_rpc.php`。
- 环境变量只在配置文件中读取，业务类统一使用 `config()` 或配置仓库。
- 配置键应带包或模块前缀，避免与宿主项目冲突。

Service Provider 中使用 `mergeConfigFrom()` 提供默认值，并通过 `publishes()` 允许宿主
项目发布配置。

```bash
php artisan vendor:publish --tag=foundation-config
```

敏感信息不能写入默认配置或提交到 Git，应从宿主项目 `.env` 注入。
