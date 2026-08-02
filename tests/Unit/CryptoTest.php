<?php

namespace Chencongbao\Foundation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Chencongbao\Foundation\Exceptions\CryptoException;
use Chencongbao\Foundation\Support\Crypto\Aes;
use Chencongbao\Foundation\Support\Crypto\Rsa;
use Chencongbao\Foundation\Support\Crypto\TripleDes;

final class CryptoTest extends TestCase
{
    private static string $privateKey;
    private static string $publicKey;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        $privateKey = '';
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        self::$privateKey = $privateKey;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::$publicKey = (string) $details['key'];
    }

    public function test_aes_ecb_and_cbc_base64_are_compatible_with_openssl(): void
    {
        $data = '{"order":"P1001","amount":"12.50"}';
        $ecbKey = '12345678901234567890123456789012';
        $cbcKey = '1234567890123456';
        $iv = '0102030405060708';

        $expectedEcb = base64_encode((string) openssl_encrypt(
            $data,
            'aes-256-ecb',
            $ecbKey,
            OPENSSL_RAW_DATA
        ));
        $expectedCbc = base64_encode((string) openssl_encrypt(
            $data,
            'aes-128-cbc',
            $cbcKey,
            OPENSSL_RAW_DATA,
            $iv
        ));

        self::assertSame($expectedEcb, Aes::encryptEcbBase64($data, $ecbKey));
        self::assertSame($data, Aes::decryptEcbBase64($expectedEcb, $ecbKey));
        self::assertSame($expectedCbc, Aes::encryptCbcBase64($data, $cbcKey, $iv, 128));
        self::assertSame($data, Aes::decryptCbcBase64($expectedCbc, $cbcKey, $iv, 128));
    }

    public function test_aes_pbkdf2_cbc_format_can_round_trip(): void
    {
        $encrypted = Aes::encryptPbkdf2CbcBase64('payment payload', 'merchant-password');

        self::assertSame('payment payload', Aes::decryptPbkdf2CbcBase64($encrypted, 'merchant-password'));
        self::assertGreaterThan(16, strlen((string) base64_decode($encrypted, true)));
    }

    public function test_aes_rejects_an_invalid_iv_length(): void
    {
        $this->expectException(CryptoException::class);
        Aes::encryptCbcBase64('payload', '1234567890123456', 'short', 128);
    }

    public function test_triple_des_supports_base64_and_url_base64_formats(): void
    {
        $data = 'amount=100&order=P1001';
        $key = '123456789012345678901234';
        $iv = '00000000';
        $expected = base64_encode((string) openssl_encrypt(
            $data,
            'des-ede3-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        ));

        self::assertSame($expected, TripleDes::encryptBase64($data, $key, $iv));
        self::assertSame($data, TripleDes::decryptBase64($expected, $key, $iv));
        self::assertSame(urlencode($expected), TripleDes::encryptUrlBase64($data, $key));
        self::assertSame($data, TripleDes::decryptUrlBase64(urlencode($expected), $key));
        self::assertSame(substr($key, 0, 8), TripleDes::ivFromKey($key));
    }

    public function test_rsa_signing_accepts_pem_and_base64_key_bodies(): void
    {
        $data = '{"order":"P1001"}';
        $privateBody = $this->pemBody(self::$privateKey);
        $publicBody = $this->pemBody(self::$publicKey);

        $signature = Rsa::signBase64($data, $privateBody, OPENSSL_ALGO_SHA256);
        $sha1Signature = Rsa::signRaw($data, self::$privateKey, OPENSSL_ALGO_SHA1);

        self::assertTrue(Rsa::verifyBase64($data, $signature, $publicBody, OPENSSL_ALGO_SHA256));
        self::assertTrue(Rsa::verifyRaw($data, $sha1Signature, self::$publicKey, OPENSSL_ALGO_SHA1));
        self::assertFalse(Rsa::verifyBase64($data.'changed', $signature, self::$publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_rsa_public_private_encryption_can_round_trip(): void
    {
        $data = 'payment payload';

        $publicEncrypted = Rsa::publicEncryptBase64($data, self::$publicKey);
        self::assertSame($data, Rsa::privateDecryptBase64($publicEncrypted, self::$privateKey));

        $oaepEncrypted = Rsa::publicEncryptBase64($data, self::$publicKey, OPENSSL_PKCS1_OAEP_PADDING);
        self::assertSame($data, Rsa::privateDecryptBase64(
            $oaepEncrypted,
            self::$privateKey,
            OPENSSL_PKCS1_OAEP_PADDING
        ));

        $privateEncrypted = Rsa::privateEncryptBase64($data, self::$privateKey);
        self::assertSame($data, Rsa::publicDecryptBase64($privateEncrypted, self::$publicKey));
    }

    public function test_rsa_private_public_long_payload_can_round_trip(): void
    {
        $data = str_repeat('foundation-payment-', 20);

        $encrypted = Rsa::privateEncryptLongBase64($data, self::$privateKey);

        self::assertSame($data, Rsa::publicDecryptLongBase64($encrypted, self::$publicKey));
    }

    public function test_rsa_public_private_long_payload_can_round_trip(): void
    {
        $data = str_repeat('foundation-payment-', 20);

        $encrypted = Rsa::publicEncryptLongBase64($data, self::$publicKey);

        self::assertSame($data, Rsa::privateDecryptLongBase64($encrypted, self::$privateKey));
    }

    public function test_rsa_rejects_invalid_keys_and_base64(): void
    {
        $this->expectException(CryptoException::class);
        Rsa::verifyBase64('payload', 'not-base64!', 'invalid-public-key');
    }

    private function pemBody(string $pem): string
    {
        return (string) preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem);
    }
}
