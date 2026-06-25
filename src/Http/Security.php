<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;

/**
 * HTTP security hardening applied to every request: security headers, and HTTPS
 * enforcement in production. CSP is intentionally strict — the app is
 * server-rendered with one stylesheet and no inline/3rd-party JS (fonts are the
 * only external origin).
 */
final class Security
{
    /** Redirect to HTTPS in production (respects a TLS-terminating proxy). */
    public static function enforceHttps(): void
    {
        if (strtolower((string) Config::get('APP_ENV', 'development')) !== 'production') {
            return;
        }
        if (self::isHttps()) {
            return;
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if ($host !== '') {
            header('Location: https://' . $host . $uri, true, 301);
            exit;
        }
    }

    public static function sendHeaders(): void
    {
        $prod = strtolower((string) Config::get('APP_ENV', 'development')) === 'production';

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "style-src 'self' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data:; "
            . "script-src 'self'; "
            . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'"
        );
        if ($prod) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return (string) ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https';
    }
}
