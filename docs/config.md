# 配置

包内核心配置位于 `config/`：

- `foundation.php`：包级通用配置。
- `client_ip.php`：客户端 IP、CDN 域名和可信节点配置。
- `foundation_log.php`：模块日志和 Telegram 异常通知配置。
- 独立服务使用单独配置，例如 `tron_rpc.php`。
- `foundation_custom.php`：唯一允许发布到宿主项目的差异配置模板。
- 环境变量只在配置文件中读取，业务类统一使用 `config()` 或配置仓库。
- 配置键应带包或模块前缀，避免与宿主项目冲突。

核心配置属于包的默认值，不发布到宿主项目，也不应由项目直接复制或修改。项目只发布
一份差异配置：

```bash
php artisan vendor:publish --tag=foundation-custom-config
```

发布位置：

```text
config/foundation_custom.php
```

只填写需要覆盖或新增的配置：

```php
return [
    'foundation_log' => [
        'modules' => [
            'payment' => [
                'enabled' => true,
                'path' => storage_path('logs/{date}/foundation/payment.log'),
                'level' => 'info',
            ],
        ],
    ],

    'client_ip' => [
        'nodes' => [
            'private_cdn' => [
                'name' => 'Private CDN',
                'type' => 'custom_proxy',
                'enabled' => true,
                'domains' => ['api.example.com'],
                'headers' => ['X-Real-IP'],
                'proxies' => ['10.0.0.0/8'],
            ],
        ],
    ],
];
```

合并规则：

- 关联数组递归覆盖，所以添加 `payment` 模块不会删除 `tron_rpc` 模块；
- 普通列表整体替换，例如配置 `domains` 后使用项目提供的完整域名列表；
- 未填写的配置继续使用包内默认值；
- `php artisan config:cache` 和常驻 Queue Worker 均支持该合并方式。

## 旧项目迁移

旧版本曾将核心配置直接发布为 `config/client_ip.php`、`config/foundation_log.php` 等文件。
升级时先把项目自定义部分迁移到 `config/foundation_custom.php`，再移除旧的包配置副本，
否则 Laravel 会继续让旧文件覆盖包内新默认值。

迁移后执行：

```bash
php artisan optimize:clear
php artisan queue:restart
```

敏感信息不能写入默认配置或提交到 Git，应从宿主项目 `.env` 注入。

Telegram Webhook 可配置请求 Secret Token 和 Update 去重时间：

```dotenv
FOUNDATION_TELEGRAM_WEBHOOK_SECRET_TOKEN=webhook_secret-123
FOUNDATION_TELEGRAM_WEBHOOK_DEDUPLICATE_SECONDS=600
```
