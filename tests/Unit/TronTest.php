<?php

namespace Chencongbao\Foundation\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Chencongbao\Foundation\Support\Tron;

class TronTest extends TestCase
{
    private const BASE58_ADDRESS = 'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb';
    private const HEX_ADDRESS = '410000000000000000000000000000000000000000';

    public function test_it_validates_and_converts_tron_addresses(): void
    {
        $this->assertTrue(Tron::isAddress(self::BASE58_ADDRESS));
        $this->assertTrue(Tron::isValidAddress(self::BASE58_ADDRESS));
        $this->assertTrue(Tron::isHexAddress(self::HEX_ADDRESS));
        $this->assertTrue(Tron::isHexAddress('0x'.self::HEX_ADDRESS));
        $this->assertSame(self::HEX_ADDRESS, Tron::toHexAddress(self::BASE58_ADDRESS));
        $this->assertSame('0x'.self::HEX_ADDRESS, Tron::toHexAddress(self::BASE58_ADDRESS, true));
        $this->assertSame(self::BASE58_ADDRESS, Tron::toBase58Address(self::HEX_ADDRESS));
        $this->assertSame(self::BASE58_ADDRESS, Tron::normalizeAddress('0x'.self::HEX_ADDRESS));
        $this->assertTrue(Tron::sameAddress(self::BASE58_ADDRESS, self::HEX_ADDRESS));
    }

    public function test_it_rejects_invalid_addresses_and_bad_base58_checksums(): void
    {
        $this->assertFalse(Tron::isAddress('T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWbc'));
        $this->assertFalse(Tron::isValidAddress('T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWbc'));
        $this->assertFalse(Tron::isAddress('0x'.self::HEX_ADDRESS));
        $this->assertFalse(Tron::isHexAddress('40'.str_repeat('0', 40)));
        $this->assertFalse(Tron::sameAddress(self::BASE58_ADDRESS, 'invalid'));

        $this->expectException(InvalidArgumentException::class);
        Tron::toBase58Address('invalid');
    }

    public function test_it_validates_and_normalizes_transaction_ids(): void
    {
        $transactionId = str_repeat('A1', 32);

        $this->assertTrue(Tron::isHash($transactionId));
        $this->assertTrue(Tron::isHash('0x'.$transactionId));
        $this->assertTrue(Tron::isTransactionId($transactionId));
        $this->assertTrue(Tron::isTransactionId('0x'.$transactionId));
        $this->assertSame(strtolower($transactionId), Tron::normalizeHash('0x'.$transactionId));
        $this->assertSame(strtolower($transactionId), Tron::normalizeTransactionId('0x'.$transactionId));
        $this->assertFalse(Tron::isHash(str_repeat('a', 63)));
        $this->assertFalse(Tron::isHash(str_repeat('z', 64)));
        $this->assertFalse(Tron::isTransactionId(str_repeat('a', 63)));
        $this->assertFalse(Tron::isTransactionId(str_repeat('z', 64)));
    }

    public function test_it_validates_private_key_format_and_secp256k1_range(): void
    {
        $this->assertTrue(Tron::isPrivateKey(str_pad('1', 64, '0', STR_PAD_LEFT)));
        $this->assertTrue(Tron::isPrivateKey('0x'.str_repeat('a', 64)));
        $this->assertFalse(Tron::isPrivateKey(str_repeat('0', 64)));
        $this->assertFalse(Tron::isPrivateKey(str_repeat('f', 64)));
        $this->assertFalse(Tron::isPrivateKey('secret'));
    }

    public function test_it_converts_trx_and_sun_without_floating_point_math(): void
    {
        $this->assertSame('1000000', Tron::trxToSun('1'));
        $this->assertSame('1234567', Tron::trxToSun('1.234567'));
        $this->assertSame('1', Tron::trxToSun('0.000001'));
        $this->assertSame('1.234567', Tron::sunToTrx('1234567'));
        $this->assertSame('1', Tron::sunToTrx('1000000'));
        $this->assertSame('1.000000', Tron::sunToTrx('1000000', false));
    }

    public function test_it_converts_token_amounts_for_custom_decimals(): void
    {
        $this->assertSame('1234500000000000000', Tron::tokenToRaw('1.2345', 18));
        $this->assertSame('1.2345', Tron::rawToToken('1234500000000000000', 18));
        $this->assertSame('0', Tron::tokenToRaw('0.000000', 6));
        $this->assertSame('100', Tron::rawToToken('000100', 0));
    }

    public function test_it_rejects_amounts_that_would_require_rounding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Tron::trxToSun('1.0000001');
    }
}
