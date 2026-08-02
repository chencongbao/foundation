<?php

namespace Chencongbao\Foundation\Support\Crypto;

use Chencongbao\Foundation\Exceptions\CryptoException;

/**
 * RSA 加解密与签名工具，支持完整 PEM 或仅 Base64 密钥正文。
 */
final class Rsa
{
    public static function signRaw(
        string $data,
        string $privateKey,
        int|string $algorithm = OPENSSL_ALGO_SHA256,
        ?string $passphrase = null
    ): string {
        $signature = '';
        $key = self::privateKey($privateKey, $passphrase);
        self::clearOpenSslErrors();
        if (!openssl_sign($data, $signature, $key, $algorithm)) {
            throw new CryptoException('RSA 签名失败。'.self::opensslError());
        }

        return $signature;
    }

    public static function signBase64(
        string $data,
        string $privateKey,
        int|string $algorithm = OPENSSL_ALGO_SHA256,
        ?string $passphrase = null
    ): string {
        return base64_encode(self::signRaw($data, $privateKey, $algorithm, $passphrase));
    }

    public static function verifyRaw(
        string $data,
        string $signature,
        string $publicKey,
        int|string $algorithm = OPENSSL_ALGO_SHA256
    ): bool {
        $key = self::publicKey($publicKey);
        self::clearOpenSslErrors();
        $result = openssl_verify($data, $signature, $key, $algorithm);
        if ($result === -1) {
            throw new CryptoException('RSA 验签执行失败。'.self::opensslError());
        }

        return $result === 1;
    }

    public static function verifyBase64(
        string $data,
        string $signatureBase64,
        string $publicKey,
        int|string $algorithm = OPENSSL_ALGO_SHA256
    ): bool {
        return self::verifyRaw($data, self::decodeBase64($signatureBase64, 'RSA 签名'), $publicKey, $algorithm);
    }

    public static function publicEncryptBase64(
        string $data,
        string $publicKey,
        int $padding = OPENSSL_PKCS1_PADDING
    ): string {
        $encrypted = '';
        $key = self::publicKey($publicKey);
        self::clearOpenSslErrors();
        if (!openssl_public_encrypt($data, $encrypted, $key, $padding)) {
            throw new CryptoException('RSA 公钥加密失败。'.self::opensslError());
        }

        return base64_encode($encrypted);
    }

    public static function privateDecryptBase64(
        string $encryptedBase64,
        string $privateKey,
        int $padding = OPENSSL_PKCS1_PADDING,
        ?string $passphrase = null
    ): string {
        $decrypted = '';
        $key = self::privateKey($privateKey, $passphrase);
        self::clearOpenSslErrors();
        if (!openssl_private_decrypt(
            self::decodeBase64($encryptedBase64, 'RSA 密文'),
            $decrypted,
            $key,
            $padding
        )) {
            throw new CryptoException('RSA 私钥解密失败。'.self::opensslError());
        }

        return $decrypted;
    }

    public static function privateEncryptBase64(
        string $data,
        string $privateKey,
        int $padding = OPENSSL_PKCS1_PADDING,
        ?string $passphrase = null
    ): string {
        $encrypted = '';
        $key = self::privateKey($privateKey, $passphrase);
        self::clearOpenSslErrors();
        if (!openssl_private_encrypt($data, $encrypted, $key, $padding)) {
            throw new CryptoException('RSA 私钥加密失败。'.self::opensslError());
        }

        return base64_encode($encrypted);
    }

    public static function publicDecryptBase64(
        string $encryptedBase64,
        string $publicKey,
        int $padding = OPENSSL_PKCS1_PADDING
    ): string {
        $decrypted = '';
        $key = self::publicKey($publicKey);
        self::clearOpenSslErrors();
        if (!openssl_public_decrypt(
            self::decodeBase64($encryptedBase64, 'RSA 密文'),
            $decrypted,
            $key,
            $padding
        )) {
            throw new CryptoException('RSA 公钥解密失败。'.self::opensslError());
        }

        return $decrypted;
    }

    public static function publicEncryptLongBase64(
        string $data,
        string $publicKey,
        int $padding = OPENSSL_PKCS1_PADDING
    ): string {
        $key = self::publicKey($publicKey);
        $blockBytes = self::keyBytes($key);
        $chunkBytes = self::maximumPlaintextBytes($blockBytes, $padding);
        $encrypted = '';
        foreach (str_split($data, $chunkBytes) as $chunk) {
            $part = '';
            self::clearOpenSslErrors();
            if (!openssl_public_encrypt($chunk, $part, $key, $padding)) {
                throw new CryptoException('RSA 公钥分段加密失败。'.self::opensslError());
            }
            $encrypted .= $part;
        }

        return base64_encode($encrypted);
    }

    public static function privateDecryptLongBase64(
        string $encryptedBase64,
        string $privateKey,
        int $padding = OPENSSL_PKCS1_PADDING,
        ?string $passphrase = null
    ): string {
        $key = self::privateKey($privateKey, $passphrase);
        $blockBytes = self::keyBytes($key);
        $encrypted = self::decodeBase64($encryptedBase64, 'RSA 密文');
        if ($encrypted === '' || strlen($encrypted) % $blockBytes !== 0) {
            throw new CryptoException('RSA 分段密文长度无效。');
        }

        $decrypted = '';
        foreach (str_split($encrypted, $blockBytes) as $chunk) {
            $part = '';
            self::clearOpenSslErrors();
            if (!openssl_private_decrypt($chunk, $part, $key, $padding)) {
                throw new CryptoException('RSA 私钥分段解密失败。'.self::opensslError());
            }
            $decrypted .= $part;
        }

        return $decrypted;
    }

    public static function privateEncryptLongBase64(
        string $data,
        string $privateKey,
        int $padding = OPENSSL_PKCS1_PADDING,
        ?string $passphrase = null
    ): string {
        if ($padding === OPENSSL_PKCS1_OAEP_PADDING) {
            throw new CryptoException('RSA 私钥加密不支持 OAEP Padding。');
        }

        $key = self::privateKey($privateKey, $passphrase);
        $blockBytes = self::keyBytes($key);
        $chunkBytes = self::maximumPlaintextBytes($blockBytes, $padding);
        $encrypted = '';
        foreach (str_split($data, $chunkBytes) as $chunk) {
            $part = '';
            self::clearOpenSslErrors();
            if (!openssl_private_encrypt($chunk, $part, $key, $padding)) {
                throw new CryptoException('RSA 私钥分段加密失败。'.self::opensslError());
            }
            $encrypted .= $part;
        }

        return base64_encode($encrypted);
    }

    public static function publicDecryptLongBase64(
        string $encryptedBase64,
        string $publicKey,
        int $padding = OPENSSL_PKCS1_PADDING
    ): string {
        $key = self::publicKey($publicKey);
        $blockBytes = self::keyBytes($key);
        $encrypted = self::decodeBase64($encryptedBase64, 'RSA 密文');
        if ($encrypted === '' || strlen($encrypted) % $blockBytes !== 0) {
            throw new CryptoException('RSA 分段密文长度无效。');
        }

        $decrypted = '';
        foreach (str_split($encrypted, $blockBytes) as $chunk) {
            $part = '';
            self::clearOpenSslErrors();
            if (!openssl_public_decrypt($chunk, $part, $key, $padding)) {
                throw new CryptoException('RSA 公钥分段解密失败。'.self::opensslError());
            }
            $decrypted .= $part;
        }

        return $decrypted;
    }

    /** @return mixed OpenSSLAsymmetricKey|resource */
    public static function publicKey(string $key)
    {
        $key = trim($key);
        if ($key === '') {
            throw new CryptoException('RSA 公钥不能为空。');
        }

        self::clearOpenSslErrors();
        $resource = openssl_pkey_get_public(self::isPem($key) ? $key : self::publicPem($key));
        if ($resource === false) {
            throw new CryptoException('RSA 公钥格式无效。'.self::opensslError());
        }

        return $resource;
    }

    /** @return mixed OpenSSLAsymmetricKey|resource */
    public static function privateKey(string $key, ?string $passphrase = null)
    {
        $key = trim($key);
        if ($key === '') {
            throw new CryptoException('RSA 私钥不能为空。');
        }

        $candidates = self::isPem($key)
            ? [$key]
            : [self::privatePem($key, false), self::privatePem($key, true)];
        self::clearOpenSslErrors();
        foreach ($candidates as $candidate) {
            $resource = openssl_pkey_get_private($candidate, $passphrase ?? '');
            if ($resource !== false) {
                self::clearOpenSslErrors();
                return $resource;
            }
        }

        throw new CryptoException('RSA 私钥格式或密码无效。'.self::opensslError());
    }

    private static function keyBytes($key): int
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['bits'])) {
            throw new CryptoException('无法读取 RSA 密钥长度。'.self::opensslError());
        }

        return (int) ceil(((int) $details['bits']) / 8);
    }

    private static function maximumPlaintextBytes(int $blockBytes, int $padding): int
    {
        return match ($padding) {
            OPENSSL_PKCS1_PADDING => $blockBytes - 11,
            OPENSSL_PKCS1_OAEP_PADDING => $blockBytes - 42,
            OPENSSL_NO_PADDING => $blockBytes,
            default => throw new CryptoException('不支持该 RSA 分段加密 Padding。'),
        };
    }

    private static function publicPem(string $key): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".self::keyBody($key)."\n-----END PUBLIC KEY-----";
    }

    private static function privatePem(string $key, bool $pkcs1): string
    {
        $type = $pkcs1 ? 'RSA PRIVATE KEY' : 'PRIVATE KEY';

        return "-----BEGIN {$type}-----\n".self::keyBody($key)."\n-----END {$type}-----";
    }

    private static function keyBody(string $key): string
    {
        return trim(chunk_split((string) preg_replace('/\s+/', '', $key), 64, "\n"));
    }

    private static function isPem(string $key): bool
    {
        return str_contains($key, '-----BEGIN ');
    }

    private static function decodeBase64(string $value, string $label): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new CryptoException("{$label}不是有效的 Base64。");
        }

        return $decoded;
    }

    private static function opensslError(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? '' : ' OpenSSL：'.implode(' | ', $errors);
    }

    private static function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
