<?php

declare(strict_types=1);

namespace App\Support;

use App\Config;

/**
 * Append-only debug log for the OneSync write-back API. Off by default; enable
 * with ONESYNC_API_DEBUG=true to diagnose why OneSync's calls fail (wrong token,
 * wrong header, malformed JSON, unknown uniqueId, …). One JSON object per line.
 *
 * Writes to ONESYNC_API_LOG (default /var/idm/onesync/api_debug.log). If that
 * path isn't writable it falls back to the PHP error log, so a diagnostic is
 * never silently lost. Logging never throws — it must not break the API.
 */
final class ApiLog
{
    public static function enabled(): bool
    {
        return Config::bool('ONESYNC_API_DEBUG', false);
    }

    public static function path(): string
    {
        $p = (string) Config::get('ONESYNC_API_LOG', '');
        return $p !== '' ? $p : '/var/idm/onesync/api_debug.log';
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
            error_log('[idm][onesync-api] ' . $line);
        } catch (\Throwable $e) {
            error_log('[idm][onesync-api] log failed: ' . $e->getMessage());
        }
    }
}
