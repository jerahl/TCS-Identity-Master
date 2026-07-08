<?php

declare(strict_types=1);

namespace App\Support;

/**
 * API-key token format + hashing (pure, no I/O — unit tested).
 *
 * A key is `tcsidm_` + 40 lowercase hex chars (160 bits of entropy). Only its
 * SHA-256 hash is ever stored; the raw value is shown to the user once. The
 * leading `token_prefix` is kept in the clear so a key can be recognised in the
 * UI/CLI without revealing the secret.
 */
final class ApiKey
{
    public const PREFIX = 'tcsidm_';

    /** Secret bytes after the prefix, as hex. 20 bytes -> 40 hex chars. */
    private const SECRET_BYTES = 20;

    /** Chars kept as the non-secret display hint (prefix + a few of the secret). */
    private const PREFIX_LEN = 13;

    /** Mint a new random key (the full secret — persist only its hash). */
    public static function generate(): string
    {
        return self::PREFIX . bin2hex(random_bytes(self::SECRET_BYTES));
    }

    /** Storage hash for a key. */
    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /** Non-secret display hint, e.g. "tcsidm_ab12cd". */
    public static function displayPrefix(string $key): string
    {
        return substr($key, 0, self::PREFIX_LEN);
    }

    /** Shape check before hitting the DB (defends against junk lookups). */
    public static function looksValid(string $key): bool
    {
        $expectedLen = strlen(self::PREFIX) + (self::SECRET_BYTES * 2);
        if (strlen($key) !== $expectedLen || !str_starts_with($key, self::PREFIX)) {
            return false;
        }
        $secret = substr($key, strlen(self::PREFIX));
        return ctype_xdigit($secret);
    }
}
