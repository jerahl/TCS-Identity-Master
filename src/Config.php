<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Tiny environment-first configuration loader.
 *
 * Secrets and paths come from the process environment; a local `.env` file is a
 * convenience for dev/ops and is parsed here. Real environment variables always
 * win over `.env` so container/host config can override the file. Nothing is
 * hardcoded — callers ask for keys and get the resolved value (or a default).
 */
final class Config
{
    /** Parsed .env values (real env vars are read directly, not stored here). */
    private static array $fileValues = [];
    private static bool $loaded = false;

    /**
     * Parse a .env file (if present) into memory. Idempotent. Does NOT overwrite
     * variables already present in the real environment.
     */
    public static function load(string $envPath): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($envPath) || !is_readable($envPath)) {
            // No .env is fine — everything may come from the real environment.
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            // Strip a trailing inline comment for unquoted values.
            if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }

            // Unwrap surrounding quotes.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$fileValues[$key] = $value;
        }
    }

    /** Get a config value: real env var first, then .env, then default. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        if (array_key_exists($key, self::$fileValues) && self::$fileValues[$key] !== '') {
            return self::$fileValues[$key];
        }
        return $default;
    }

    /** Get a required config value or throw — use for things with no safe default. */
    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new RuntimeException("Missing required config: {$key}");
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }
}
