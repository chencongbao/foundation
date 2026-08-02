# AES、RSA 与 3DES 工具

Foundation 在 `Support\Crypto` 提供不依赖 Laravel、数据库或远程服务的密码协议工具：

- `Aes`：AES-128/256 的 CBC、ECB、Base64 和 PBKDF2-CBC 兼容格式；
- `Rsa`：RSA 签名、验签、公私钥加解密及长文本分段处理；
- `TripleDes`：存量支付接口使用的 3DES-CBC、Base64 和 URL Base64；
- 所有执行失败统一抛出 `CryptoException`，不会使用空字符串掩盖失败。

这些工具用于严格复现第三方接口协议，不负责选择算法。调用前必须确认通道文档中的
Cipher、Padding、摘要算法、Key/IV 编码和最终输出编码。

## AES

```php
use Chencongbao\Foundation\Support\Crypto\Aes;

$encrypted = Aes::encryptCbcBase64($plainText, $key, $iv, 128);
$plainText = Aes::decryptCbcBase64($encrypted, $key, $iv, 128);

$encrypted = Aes::encryptEcbBase64($plainText, $key, 256);
$plainText = Aes::decryptEcbBase64($encrypted, $key, 256);
```

需要自行指定 Cipher 时：

```php
$encrypted = Aes::encryptBase64($plainText, $key, Aes::AES_256_CBC, $iv);
$plainText = Aes::decryptBase64($encrypted, $key, Aes::AES_256_CBC, $iv);
```

兼容现有 `CopyAes` 的“PBKDF2 派生 Key、随机 16 字节 IV 前置密文”格式：

```php
$encrypted = Aes::encryptPbkdf2CbcBase64($plainText, $password);
$plainText = Aes::decryptPbkdf2CbcBase64($encrypted, $password);
```

## RSA

```php
use Chencongbao\Foundation\Support\Crypto\Rsa;

$signature = Rsa::signBase64($data, $privateKey, OPENSSL_ALGO_SHA256);
$valid = Rsa::verifyBase64($data, $signature, $publicKey, OPENSSL_ALGO_SHA256);

$encrypted = Rsa::publicEncryptBase64($data, $publicKey, OPENSSL_PKCS1_PADDING);
$data = Rsa::privateDecryptBase64($encrypted, $privateKey, OPENSSL_PKCS1_PADDING);

$encrypted = Rsa::privateEncryptBase64($data, $privateKey);
$data = Rsa::publicDecryptBase64($encrypted, $publicKey);
```

密钥参数既可以传完整 PEM，也可以传去掉头尾和换行后的 Base64 正文。加密私钥可通过
最后一个 `$passphrase` 参数传入密码。

超过单个 RSA Block 的存量协议可以使用：

```php
$encrypted = Rsa::privateEncryptLongBase64($longData, $privateKey);
$longData = Rsa::publicDecryptLongBase64($encrypted, $publicKey);

$encrypted = Rsa::publicEncryptLongBase64($longData, $publicKey);
$longData = Rsa::privateDecryptLongBase64($encrypted, $privateKey);
```

## 3DES

```php
use Chencongbao\Foundation\Support\Crypto\TripleDes;

$encrypted = TripleDes::encryptBase64($plainText, $key, $iv);
$plainText = TripleDes::decryptBase64($encrypted, $key, $iv);

$encrypted = TripleDes::encryptUrlBase64($plainText, $key, '00000000');
$plainText = TripleDes::decryptUrlBase64($encrypted, $key, '00000000');

$iv = TripleDes::ivFromKey($key);
```

3DES 已属于旧算法，只用于第三方支付协议的存量兼容。新协议优先采用带认证的现代算法，
例如 AES-GCM；Foundation 不会擅自替换第三方已经规定的算法。

## 异常处理

```php
use Chencongbao\Foundation\Exceptions\CryptoException;

try {
    $plainText = Aes::decryptCbcBase64($encrypted, $key, $iv, 256);
} catch (CryptoException $exception) {
    // 记录通道和订单信息；不要记录私钥、完整 Key 或明文敏感数据。
}
```

工具类不会写入日志，避免密钥或支付报文被意外记录。日志记录由调用项目按订单维度处理。
