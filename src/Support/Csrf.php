<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Per-session CSRF token. Every state-changing form embeds token() in a hidden
 * field; controllers call check() before acting. A timing-safe comparison guards
 * against token-guessing. (Full security hardening — headers, SameSite, SAML —
 * lands in Milestone 7; this is the foundation every write form uses.)
 */
final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session must be started before issuing a CSRF token.');
        }
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function check(?string $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? '';
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /** Hidden form field markup (value already HTML-safe — hex token). */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }
}
