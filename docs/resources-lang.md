# Resources 与多语言

语言文件放在 `resources/lang/{locale}/`，例如：

```text
resources/lang/zh_CN/messages.php
resources/lang/en/messages.php
```

使用包命名空间读取翻译：

```php
__('foundation::messages.invalid_argument');
```

异常和验证规则中面向用户的消息应优先使用语言文件；日志和开发异常可以保留明确的
技术描述。
