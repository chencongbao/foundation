<?php

namespace Chencongbao\Foundation\Support;

use InvalidArgumentException;

/**
 * 不依赖节点或 RPC 的 TRON 基础工具。
 */
final class Tron
{
    public const ADDRESS_PREFIX = '41';
    public const TRX_DECIMALS = 6;

    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private const PRIVATE_KEY_MAX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364140';

    public static function isAddress(string $address): bool
    {
        $address = trim($address);
        if (strlen($address) !== 34 || $address[0] !== 'T') {
            return false;
        }

        try {
            $decoded = self::base58Decode($address);
        } catch (InvalidArgumentException) {
            return false;
        }

        if (strlen($decoded) !== 25) {
            return false;
        }

        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);

        return ord($payload[0]) === hexdec(self::ADDRESS_PREFIX)
            && hash_equals(self::checksum($payload), $checksum);
    }

    /**
     * 验证 TRON Base58Check 地址是否合法。
     */
    public static function isValidAddress(string $address): bool
    {
        return self::isAddress($address);
    }

    public static function isHexAddress(string $address): bool
    {
        $address = self::stripHexPrefix(trim($address));

        return strlen($address) === 42
            && ctype_xdigit($address)
            && str_starts_with(strtoupper($address), self::ADDRESS_PREFIX);
    }

    /**
     * 将 Base58Check 或 Hex 地址统一转换为大写 41 开头的 Hex 地址。
     */
    public static function toHexAddress(string $address, bool $withPrefix = false): string
    {
        $address = trim($address);
        if (self::isHexAddress($address)) {
            $hex = strtoupper(self::stripHexPrefix($address));

            return $withPrefix ? '0x'.$hex : $hex;
        }
        if (!self::isAddress($address)) {
            throw new InvalidArgumentException('TRON 地址格式无效。');
        }

        $hex = strtoupper(bin2hex(substr(self::base58Decode($address), 0, 21)));

        return $withPrefix ? '0x'.$hex : $hex;
    }

    /**
     * 将 Base58Check 或 Hex 地址统一转换为 Base58Check 地址。
     */
    public static function toBase58Address(string $address): string
    {
        $address = trim($address);
        if (self::isAddress($address)) {
            return $address;
        }
        if (!self::isHexAddress($address)) {
            throw new InvalidArgumentException('TRON 地址格式无效。');
        }

        $payload = hex2bin(self::stripHexPrefix($address));
        if ($payload === false) {
            throw new InvalidArgumentException('TRON Hex 地址格式无效。');
        }

        return self::base58Encode($payload.self::checksum($payload));
    }

    public static function normalizeAddress(string $address): string
    {
        return self::toBase58Address($address);
    }

    public static function sameAddress(string $first, string $second): bool
    {
        try {
            return hash_equals(self::toHexAddress($first), self::toHexAddress($second));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function isTransactionId(string $transactionId): bool
    {
        return self::isHash($transactionId);
    }

    public static function normalizeTransactionId(string $transactionId): string
    {
        try {
            return self::normalizeHash($transactionId);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('TRON 交易 ID 格式无效。');
        }
    }

    /**
     * 验证交易 ID、区块 ID 等 TRON 32 字节 Hash。
     */
    public static function isHash(string $hash): bool
    {
        $hash = self::stripHexPrefix(trim($hash));

        return strlen($hash) === 64 && ctype_xdigit($hash);
    }

    public static function normalizeHash(string $hash): string
    {
        if (!self::isHash($hash)) {
            throw new InvalidArgumentException('TRON Hash 格式无效。');
        }

        return strtolower(self::stripHexPrefix(trim($hash)));
    }

    /**
     * 仅校验 secp256k1 私钥的格式和值域，不验证私钥是否对应某个地址。
     */
    public static function isPrivateKey(string $privateKey): bool
    {
        $privateKey = strtoupper(self::stripHexPrefix(trim($privateKey)));
        if (strlen($privateKey) !== 64 || !ctype_xdigit($privateKey)) {
            return false;
        }

        return trim($privateKey, '0') !== ''
            && strcmp($privateKey, self::PRIVATE_KEY_MAX) <= 0;
    }

    public static function trxToSun(int|string $amount): string
    {
        return self::tokenToRaw($amount, self::TRX_DECIMALS);
    }

    public static function sunToTrx(int|string $amount, bool $trimTrailingZeros = true): string
    {
        return self::rawToToken($amount, self::TRX_DECIMALS, $trimTrailingZeros);
    }

    /**
     * 将十进制 Token 数量转换为最小单位，过程不使用浮点数。
     */
    public static function tokenToRaw(int|string $amount, int $decimals): string
    {
        self::assertDecimals($decimals);
        $amount = trim((string) $amount);
        if (preg_match('/^\d+(?:\.\d+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException('TRON Token 数量必须是非负十进制数字。');
        }

        [$integer, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        if (strlen($fraction) > $decimals) {
            throw new InvalidArgumentException("TRON Token 数量最多支持 {$decimals} 位小数。");
        }

        $raw = ltrim($integer.str_pad($fraction, $decimals, '0'), '0');

        return $raw === '' ? '0' : $raw;
    }

    /**
     * 将最小单位转换为十进制 Token 数量，过程不使用浮点数。
     */
    public static function rawToToken(int|string $amount, int $decimals, bool $trimTrailingZeros = true): string
    {
        self::assertDecimals($decimals);
        $amount = self::normalizeUnsignedInteger($amount);
        if ($decimals === 0) {
            return $amount;
        }

        $digits = str_pad($amount, $decimals + 1, '0', STR_PAD_LEFT);
        $integer = ltrim(substr($digits, 0, -$decimals), '0');
        $fraction = substr($digits, -$decimals);
        if ($trimTrailingZeros) {
            $fraction = rtrim($fraction, '0');
        }

        return ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);
    }

    private static function assertDecimals(int $decimals): void
    {
        if ($decimals < 0 || $decimals > 30) {
            throw new InvalidArgumentException('TRON Token 精度必须在 0 到 30 之间。');
        }
    }

    private static function normalizeUnsignedInteger(int|string $amount): string
    {
        $amount = trim((string) $amount);
        if (preg_match('/^\d+$/', $amount) !== 1) {
            throw new InvalidArgumentException('TRON 最小单位数量必须是非负整数。');
        }

        $amount = ltrim($amount, '0');

        return $amount === '' ? '0' : $amount;
    }

    private static function stripHexPrefix(string $value): string
    {
        return str_starts_with(strtolower($value), '0x') ? substr($value, 2) : $value;
    }

    private static function checksum(string $payload): string
    {
        return substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
    }

    private static function base58Decode(string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('Base58 字符串不能为空。');
        }

        $bytes = [];
        $length = strlen($value);
        for ($position = 0; $position < $length; $position++) {
            $digit = strpos(self::BASE58_ALPHABET, $value[$position]);
            if ($digit === false) {
                throw new InvalidArgumentException('Base58 字符串包含无效字符。');
            }

            $carry = $digit;
            for ($index = count($bytes) - 1; $index >= 0; $index--) {
                $carry += $bytes[$index] * 58;
                $bytes[$index] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                array_unshift($bytes, $carry & 0xff);
                $carry >>= 8;
            }
        }

        for ($position = 0; $position < $length && $value[$position] === '1'; $position++) {
            array_unshift($bytes, 0);
        }

        return $bytes === [] ? '' : pack('C*', ...$bytes);
    }

    private static function base58Encode(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $digits = [];
        $length = strlen($value);
        for ($position = 0; $position < $length; $position++) {
            $carry = ord($value[$position]);
            for ($index = count($digits) - 1; $index >= 0; $index--) {
                $carry += $digits[$index] << 8;
                $digits[$index] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            while ($carry > 0) {
                array_unshift($digits, $carry % 58);
                $carry = intdiv($carry, 58);
            }
        }

        $encoded = '';
        for ($position = 0; $position < $length && $value[$position] === "\0"; $position++) {
            $encoded .= '1';
        }
        foreach ($digits as $digit) {
            $encoded .= self::BASE58_ALPHABET[$digit];
        }

        return $encoded;
    }
}
