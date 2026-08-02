<?php

namespace Chencongbao\Foundation\Support\Crypto;

use Chencongbao\Foundation\Exceptions\CryptoException;

/**
 * 3DES-CBC 支付协议兼容工具；只用于仍明确要求 3DES 的存量接口。
 */
final class TripleDes
{
    public const CIPHER = 'des-ede3-cbc';

    public static function encryptRaw(string $data, string $key, string $iv): string
    {
        self::assertIv($iv);
        self::clearOpenSslErrors();
        $encrypted = openssl_encrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new CryptoException('3DES 加密失败。'.self::opensslError());
        }

        return $encrypted;
    }

    public static function decryptRaw(string $encrypted, string $key, string $iv): string
    {
        self::assertIv($iv);
        self::clearOpenSslErrors();
        $decrypted = openssl_decrypt($encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            throw new CryptoException('3DES 解密失败。'.self::opensslError());
        }

        return $decrypted;
    }

    public static function encryptBase64(string $data, string $key, string $iv): string
    {
        return base64_encode(self::encryptRaw($data, $key, $iv));
    }

    public static function decryptBase64(string $encryptedBase64, string $key, string $iv): string
    {
        return self::decryptRaw(self::decodeBase64($encryptedBase64), $key, $iv);
    }

    public static function encryptUrlBase64(string $data, string $key, string $iv = '00000000'): string
    {
        return urlencode(self::encryptBase64($data, $key, $iv));
    }

    public static function decryptUrlBase64(string $encrypted, string $key, string $iv = '00000000'): string
    {
        return self::decryptBase64(urldecode($encrypted), $key, $iv);
    }

    public static function ivFromKey(string $key): string
    {
        if (strlen($key) < 8) {
            throw new CryptoException('3DES 密钥不足 8 字节，无法生成 IV。');
        }

        return substr($key, 0, 8);
    }

    private static function assertIv(string $iv): void
    {
        if (strlen($iv) !== 8) {
            throw new CryptoException('3DES-CBC 要求 8 字节 IV，实际为 '.strlen($iv).' 字节。');
        }
    }

    private static function decodeBase64(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new CryptoException('3DES 密文不是有效的 Base64。');
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
