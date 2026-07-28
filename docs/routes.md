# Routes

包路由文件放在 `routes/`，建议按用途拆为 `web.php`、`api.php` 或具体模块文件。

路由必须使用唯一前缀和名称前缀，避免与宿主项目冲突。控制器放在
`src/Http/Controllers/`。

没有公共 HTTP 能力时不要添加路由。内部服务调用不应为了复用而包装成 HTTP 接口。
