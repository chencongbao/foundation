# Support

`src/Support/` 放无状态、低依赖的通用工具类。

当前提供：

- `Arr`：常用数组读取、筛选和删除；
- `ConfigList`：将逗号分隔的环境配置转换为去重数组；
- `Str`：脱敏、命名格式和随机字符串；
- `Money`：使用最小货币单位安全格式化金额；
- `Tron`：不调用节点的 TRON 地址、交易 ID、私钥格式和数量换算工具；
- `Tree`：将扁平数组构造成树。
- `Crypto\Aes`、`Crypto\Rsa`、`Crypto\TripleDes`：支付协议常用的本地密码工具。

Support 类不能访问数据库、缓存、Request 或远程服务。涉及这些依赖时应放入
`Services/`。新增方法前先检查 Laravel 自身是否已经提供，避免重复封装。

## TRON 基础工具

`Chencongbao\Foundation\Support\Tron` 只处理本地数据，不访问 FullNode、SolidityNode、
数据库或 RPC：

```php
use Chencongbao\Foundation\Support\Tron;

Tron::isAddress($address);
Tron::isValidAddress($address);
Tron::isHexAddress($hexAddress);

$hex = Tron::toHexAddress($address);
$base58 = Tron::toBase58Address($hex);
$address = Tron::normalizeAddress($address);
$same = Tron::sameAddress($firstAddress, $secondAddress);

$validHash = Tron::isHash($hash);
$hash = Tron::normalizeHash($hash);
$validTxId = Tron::isTransactionId($txId);
$txId = Tron::normalizeTransactionId($txId);
$validPrivateKey = Tron::isPrivateKey($privateKey);

$sun = Tron::trxToSun('1.234567'); // 1234567
$trx = Tron::sunToTrx('1234567'); // 1.234567

$raw = Tron::tokenToRaw('1.25', 18);
$amount = Tron::rawToToken($raw, 18);
```

地址校验会验证 TRON `0x41` 网络前缀和 Base58Check 校验和，不是只判断是否以 `T`
开头。Hex 地址支持有或没有 `0x` 的输入，标准输出使用大写 `41` 前缀。

`isHash()` 用于校验交易 ID、区块 ID 等 32 字节 TRON Hash，要求内容为 64 位
十六进制字符，可带 `0x` 前缀。`isTransactionId()` 是语义更明确的交易 Hash 校验入口。

金额换算只接受 `int|string`，不会使用浮点数。输入小数位超过 Token 精度时直接抛出
`InvalidArgumentException`，不会静默四舍五入。`isPrivateKey()` 只校验 32 字节 Hex
格式和 secp256k1 私钥值域，不会验证它是否属于某个地址，也不会记录或保存私钥。

AES、RSA 与 3DES 的算法、编码、异常规则和调用示例见
[密码协议工具](crypto.md)。
