# Services

`src/Services/` 放依赖 Laravel 容器、缓存、数据库或外部系统的服务。

按功能域分目录，例如：

- `Services/Config/`：通用配置能力；
- `Services/Tron/`：TRON RPC 客户端。

Service 应通过构造函数注入依赖，公开方法使用明确的参数和返回类型。重要服务应先在
`Contracts/` 定义接口，再由 Provider 绑定实现。

外部调用必须设置连接与请求超时，并明确哪些错误允许重试。服务中不能引用宿主项目的
`App\...` 类。
