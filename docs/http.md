# HTTP

HTTP 相关代码位于 `src/Http/`：

- `Controllers/`：包路由的控制器，保持薄层；
- `Middleware/`：跨项目通用的请求中间件；
- `Requests/`：表单验证与输入授权。

控制器只负责接收请求、调用 Service、转换响应。业务逻辑不能堆在 Controller 或
FormRequest 中。

代理 IP、安全头和鉴权规则必须由宿主项目明确配置，不能默认信任客户端传入的请求头。
Foundation 的 CDN IP 解析器说明见[客户端 IP 解析](client-ip.md)。
