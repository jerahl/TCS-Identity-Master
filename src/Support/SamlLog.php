<?php

declare(strict_types=1);

namespace App\Support;

use App\Config;

/**
 * Append-only debug log for SAML SSO. Off by default; enable with SAML_DEBUG=true
 * to capture WHY a login failed (destination/HTTPS mismatch, signature/cert
 * problems, clock skew, unsigned assertion, …) when php-fpm worker error_log
 * output isn't visible in the journal.
 *
 * Writes to SAML_LOG (default /var/idm/saml/saml_debug.log). If that path isn't
 * writable it falls back to the PHP error log, so a diagnostic is never silently
 * lost. Logging never throws — it must not break the login flow.
 *
 * NOTE: with SAML_DEBUG=true the decoded SAML response is recorded, which
 * contains the user's identity attributes (PII). Enable only while debugging and
 * turn it off again afterward; the file is mode 0640 under a 0750 dir.
 */
final class SamlLog
{
    public static function enabled(): bool
    {
        return Config::bool('SAML_DEBUG', false);
    }

    public static function path(): string
    {
        $p = (string) Config::get('SAML_LOG', '');
        return $p !== '' ? $p : '/var/idm/saml/saml_debug.log';
    }

    /**
     * Record a login/ACS failure: the reason plus, when present, the decoded
     * SAML response so signatures/conditions can be inspected.
     */
    public static function failure(string $phase, \Throwable $e, ?string $samlResponseB64 = null): void
    {
        if (!self::enabled()) {
            return;
        }
        $entry = [
            'phase'  => $phase,                 // 'acs' | 'login'
            'reason' => $e->getMessage(),
            'class'  => $e::class,
        ];
        if ($samlResponseB64 !== null && $samlResponseB64 !== '') {
            $decoded = base64_decode($samlResponseB64, true);
            // Inflate if it was DEFLATE-encoded (HTTP-Redirect binding); the
            // POST binding is plain base64, so a failed inflate is expected there.
            $inflated = $decoded !== false ? @gzinflate($decoded) : false;
            $entry['saml_response'] = $inflated !== false ? $inflated : ($decoded !== false ? $decoded : '(undecodable)');
        }
        self::write($entry);
    }

    /** @param array<string,mixed> $entry */
    public static function write(array $entry): void
    {
        if (!self::enabled()) {
            return;
        }
        try {
            $entry = ['ts' => date('c')] + $entry;
            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                return;
            }

            $path = self::path();
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            if (@file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) !== false) {
                return;
            }
            // Couldn't write the file — don't lose the diagnostic.
            error_log('[idm][saml] ' . $line);
        } catch (\Throwable $ex) {
            error_log('[idm][saml] log failed: ' . $ex->getMessage());
        }
    }
}
