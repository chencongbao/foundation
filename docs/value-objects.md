# ValueObjects

`src/ValueObjects/` 放带有校验、相等性和领域行为的不可变值，例如 Money、Phone、
Address。

ValueObject 应在构造时保证有效，推荐使用 `final readonly class`。它不应拥有数据库
身份，也不负责查询或持久化。

如果对象只用于携带数据而没有自身约束，使用 DTO；如果只是提供一组无状态静态操作，
使用 Support。
