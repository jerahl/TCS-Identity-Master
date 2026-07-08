<?php

declare(strict_types=1);

namespace App\Support;

use App\Config;
use RuntimeException;

/**
 * Encrypt-at-rest for the initial passwords OneSync writes back.
 *
 * libsodium secretbox (XSalsa20-Poly1305, authenticated) under one app-level
 * key: CREDENTIAL_ENC_KEY — 64 hex chars, generate with `openssl rand -hex 32`.
 * The database only ever stores nonce||ciphertext, so a DB dump or a DB-level
 * attacker without the app environment can't read the passwords.
 *
 * If the key is unset the password endpoint is disabled (fails closed), the
 * same pattern as ONESYNC_API_KEY.
 */
final class Crypto
{
    public const KEY_ENV = 'CREDENTIAL_ENC_KEY';

    public static function configured(): bool
    {
        return self::key() !== null;
    }

    /** The raw 32-byte key, or null when unset/malformed. */
    private static function key(): ?string
    {
        $hex = trim((string) Config::get(self::KEY_ENV, ''));
        if (strlen($hex) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2 || !ctype_xdigit($hex)) {
            return null;
        }
        return (string) hex2bin($hex);
    }

    /** Encrypt a secret for storage. Returns raw binary nonce||ciphertext. */
    public static function encrypt(string $plain): string
    {
        $key = self::key();
        if ($key === null) {
            throw new RuntimeException(self::KEY_ENV . ' is not set to 64 hex chars (generate with `openssl rand -hex 32`).');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
    }

    /** Decrypt a stored secret; null when the key is unset or the blob doesn't authenticate. */
    public static function decrypt(string $blob): ?string
    {
        $key = self::key();
        if ($key === null || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );
        return $plain === false ? null : $plain;
    }
}
