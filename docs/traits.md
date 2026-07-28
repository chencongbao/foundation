# Traits

`src/Traits/` 放小型、单一且明确的复用能力。

Trait 应按行为命名，例如 `HasSnowflakeId`、`SerializesDate`，不要使用
`ModelTrait`、`ServiceTrait` 这类范围过大的名称。

Trait 不适合隐藏复杂依赖或业务流程。需要构造函数注入、多个实现或独立测试的逻辑，
应使用 Service 和 Contract。
