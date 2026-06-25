<?php

declare(strict_types=1);

/**
 * Global view helpers. Kept tiny and dependency-free; loaded by src/bootstrap.php.
 */

if (!function_exists('e')) {
    /** HTML-escape for safe output in templates. Always use this for dynamic text. */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset')) {
    /** Cache-busted asset URL (mtime query) for files under public/. */
    function asset(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $file = dirname(__DIR__) . '/public' . $path;
        $ver = is_file($file) ? '?v=' . filemtime($file) : '';
        return $path . $ver;
    }
}

if (!function_exists('url')) {
    /** Build an in-app URL with an optional query array. */
    function url(string $path, array $query = []): string
    {
        $path = '/' . ltrim($path, '/');
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }
        return $path;
    }
}
