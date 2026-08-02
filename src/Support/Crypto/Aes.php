<?php

namespace Chencongbao\Foundation\Support\Crypto;

use Chencongbao\Foundation\Exceptions\CryptoException;

/**
 * AES 协议兼容工具；调用方必须按对接文档明确指定 Cipher、Key、IV 和编码方式。
 */
final class Aes
{
    public const AES_128_CBC = 'aes-128-cbc';
    public const AES_128_ECB = 'aes-128-ecb';
    public const AES_256_CBC = 'aes-256-cbc';
    public const AES_256_ECB = 'aes-256-ecb';

    public static function encryptRaw(
        string $data,
        string $key,
        string $cipher,
        string $iv = '',
        int $options = OPENSSL_RAW_DATA
    ): string {
        self::assertCipherAndIv($cipher, $iv);
        self::clearOpenSslErrors();
        $encrypted = openssl_encrypt($data, $cipher, $key, $options, $iv);
        if ($encrypted === false) {
            throw new CryptoException('AES 加密失败。'.self::opensslError());
        }

        return $encrypted;
    }

    public static function decryptRaw(
        string $encrypted,
        string $key,
        string $cipher,
        string $iv = '',
        int $options = OPENSSL_RAW_DATA
    ): string {
        self::assertCipherAndIv($cipher, $iv);
        self::clearOpenSslErrors();
        $decrypted = openssl_decrypt($encrypted, $cipher, $key, $options, $iv);
        if ($decrypted === false) {
            throw new CryptoException('AES 解密失败。'.self::opensslError());
        }

        return $decrypted;
    }

    public static function encryptBase64(
        string $data,
        string $key,
        string $cipher,
        string $iv = '',
        int $options = OPENSSL_RAW_DATA
    ): string {
        return base64_encode(self::encryptRaw($data, $key, $cipher, $iv, $options));
    }

    public static function decryptBase64(
        string $encryptedBase64,
        string $key,
        string $cipher,
        string $iv = '',
        int $options = OPENSSL_RAW_DATA
    ): string {
        return self::decryptRaw(
            self::decodeBase64($encryptedBase64),
            $key,
            $cipher,
            $iv,
            $options
        );
    }

    public static function encryptEcbBase64(string $data, string $key, int $bits = 256): string
    {
        return self::encryptBase64($data, $key, self::ecbCipher($bits));
    }

    public static function decryptEcbBase64(string $encryptedBase64, string $key, int $bits = 256): string
    {
        return self::decryptBase64($encryptedBase64, $key, self::ecbCipher($bits));
    }

    public static function encryptCbcBase64(string $data, string $key, string $iv, int $bits = 256): string
    {
        return self::encryptBase64($data, $key, self::cbcCipher($bits), $iv);
    }

    public static function decryptCbcBase64(
        string $encryptedBase64,
        string $key,
        string $iv,
        int $bits = 256
    ): string {
        return self::decryptBase64($encryptedBase64, $key, self::cbcCipher($bits), $iv);
    }

    /**
     * 兼容“PBKDF2 派生 Key + 随机 IV 前置到密文”的 AES-256-CBC 格式。
     */
    public static function encryptPbkdf2CbcBase64(
        string $data,
        string $password,
        int $iterations = 1000,
        string $digest = 'sha1'
    ): string {
        self::assertPbkdf2Arguments($password, $iterations, $digest);
        $key = hash_pbkdf2($digest, $password, $password, $iterations, 32, true);
        $iv = random_bytes(16);

        return base64_encode($iv.self::encryptRaw($data, $key, self::AES_256_CBC, $iv));
    }

    public static function decryptPbkdf2CbcBase64(
        string $encryptedBase64,
        string $password,
        int $iterations = 1000,
        string $digest = 'sha1'
    ): string {
        self::assertPbkdf2Arguments($password, $iterations, $digest);
        $payload = self::decodeBase64($encryptedBase64);
        if (strlen($payload) <= 16) {
            throw new CryptoException('AES PBKDF2 密文长度无效。');
        }

        $key = hash_pbkdf2($digest, $password, $password, $iterations, 32, true);
        $iv = substr($payload, 0, 16);

        return self::decryptRaw(substr($payload, 16), $key, self::AES_256_CBC, $iv);
    }

    private static function assertCipherAndIv(string $cipher, string $iv): void
    {
        if (!in_array(strtolower($cipher), array_map('strtolower', openssl_get_cipher_methods()), true)) {
            throw new CryptoException("不支持的 AES Cipher：{$cipher}。");
        }

        $ivLength = openssl_cipher_iv_length($cipher);
        if ($ivLength === false) {
            throw new CryptoException("无法读取 AES Cipher 的 IV 长度：{$cipher}。");
        }
        if (strlen($iv) !== $ivLength) {
            throw new CryptoException("AES {$cipher} 要求 {$ivLength} 字节 IV，实际为 ".strlen($iv).' 字节。');
        }
    }

    private static function assertPbkdf2Arguments(string $password, int $iterations, string $digest): void
    {
        if ($password === '') {
            throw new CryptoException('AES PBKDF2 密码不能为空。');
        }
        if ($iterations < 1) {
            throw new CryptoException('AES PBKDF2 迭代次数必须大于 0。');
        }
        if (!in_array(strtolower($digest), array_map('strtolower', hash_algos()), true)) {
            throw new CryptoException("不支持的 PBKDF2 摘要算法：{$digest}。");
        }
    }

    private static function decodeBase64(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new CryptoException('AES 密文不是有效的 Base64。');
        }

        return $decoded;
    }

    private static function ecbCipher(int $bits): string
    {
        return match ($bits) {
            128 => self::AES_128_ECB,
            256 => self::AES_256_ECB,
            default => throw new CryptoException('AES ECB 只支持 128 或 256 位。'),
        };
    }

    private static function cbcCipher(int $bits): string
    {
        return match ($bits) {
            128 => self::AES_128_CBC,
            256 => self::AES_256_CBC,
            default => throw new CryptoException('AES CBC 只支持 128 或 256 位。'),
        };
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
