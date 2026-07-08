<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\ApiKey;
use PHPUnit\Framework\TestCase;

/**
 * API-key token format + hashing. Keys are high-entropy, prefixed, and only ever
 * stored as a SHA-256 hash; looksValid() screens junk before a DB lookup.
 */
final class ApiKeyTest extends TestCase
{
    public function testGeneratedKeyHasExpectedShape(): void
    {
        $key = ApiKey::generate();
        self::assertStringStartsWith('tcsidm_', $key);
        self::assertSame(47, strlen($key)); // "tcsidm_" (7) + 40 hex
        self::assertTrue(ApiKey::looksValid($key));
    }

    public function testGeneratedKeysAreUnique(): void
    {
        self::assertNotSame(ApiKey::generate(), ApiKey::generate());
    }

    public function testHashIsDeterministicAndSha256(): void
    {
        $key = 'tcsidm_' . str_repeat('a', 40);
        self::assertSame(hash('sha256', $key), ApiKey::hash($key));
        self::assertSame(ApiKey::hash($key), ApiKey::hash($key));
        self::assertSame(64, strlen(ApiKey::hash($key)));
    }

    public function testDisplayPrefixIsNonSecretHint(): void
    {
        $key = 'tcsidm_abcdef0123456789';
        self::assertSame('tcsidm_abcdef', ApiKey::displayPrefix($key)); // prefix + 6 secret chars
    }

    public function testLooksValidRejectsMalformedKeys(): void
    {
        self::assertFalse(ApiKey::looksValid(''));
        self::assertFalse(ApiKey::looksValid('nope'));
        self::assertFalse(ApiKey::looksValid('tcsidm_short'));
        self::assertFalse(ApiKey::looksValid('wrong_' . str_repeat('a', 40)));
        self::assertFalse(ApiKey::looksValid('tcsidm_' . str_repeat('z', 40))); // non-hex
    }
}
