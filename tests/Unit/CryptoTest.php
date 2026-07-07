<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Encrypt-at-rest for OneSync's initial passwords: round-trips under a good
 * key, refuses tampered ciphertext, and fails closed when the key is unset or
 * malformed.
 */
final class CryptoTest extends TestCase
{
    protected function setUp(): void
    {
        putenv(Crypto::KEY_ENV . '=' . str_repeat('ab', 32)); // 64 hex chars
    }

    protected function tearDown(): void
    {
        putenv(Crypto::KEY_ENV); // unset — don't leak into other tests
    }

    public function testRoundTrip(): void
    {
        $blob = Crypto::encrypt('Falcon-Maple-42');
        self::assertNotSame('Falcon-Maple-42', $blob);
        self::assertStringNotContainsString('Falcon-Maple-42', $blob);
        self::assertSame('Falcon-Maple-42', Crypto::decrypt($blob));
    }

    public function testCiphertextIsNonDeterministic(): void
    {
        // Fresh nonce per call — equal plaintexts must not produce equal blobs.
        self::assertNotSame(Crypto::encrypt('same'), Crypto::encrypt('same'));
    }

    public function testTamperedBlobFailsToDecrypt(): void
    {
        $blob = Crypto::encrypt('secret');
        $blob[strlen($blob) - 1] = $blob[strlen($blob) - 1] === "\0" ? "\1" : "\0";
        self::assertNull(Crypto::decrypt($blob));
    }

    public function testTruncatedBlobFailsToDecrypt(): void
    {
        self::assertNull(Crypto::decrypt('short'));
    }

    public function testFailsClosedWithoutKey(): void
    {
        putenv(Crypto::KEY_ENV);
        self::assertFalse(Crypto::configured());
        self::assertNull(Crypto::decrypt(str_repeat('x', 64)));
        $this->expectException(RuntimeException::class);
        Crypto::encrypt('secret');
    }

    public function testRejectsMalformedKey(): void
    {
        putenv(Crypto::KEY_ENV . '=not-hex-and-too-short');
        self::assertFalse(Crypto::configured());
    }
}
