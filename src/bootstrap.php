<?php

declare(strict_types=1);

/**
 * Minimal bootstrap: makes the app runnable with OR without `composer install`.
 *
 *  - If Composer's autoloader exists, use it (vendored deps need it).
 *  - ALWAYS also register a tiny PSR-4 fallback autoloader for App\ -> src/,
 *    appended after Composer's. It never fires while Composer resolves a class;
 *    it exists so a fresh checkout works before `composer install`, and so a
 *    STALE vendor autoloader can't take the app down — a classmap dumped with
 *    `composer dump-autoload --classmap-authoritative` (or -o + APCu) before a
 *    source file existed reports the class as not found without ever checking
 *    src/, which surfaced as "Class App\Import\SyncStatusImporter not found"
 *    from bin/sync_google.php on a box with an old optimized classmap.
 *  - Load .env into the environment (without overwriting real env vars).
 */

$root = dirname(__DIR__);

$composerAutoload = $root . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require $root . '/src/helpers.php';

\App\Config::load($root . '/.env');

// Layer the admin-editable settings (app_setting) over .env, under real env
// vars, so web-console config changes take effect for both the app and the CLI.
// Best-effort: no-ops on a fresh checkout / missing table / no DB.
\App\Service\SettingsService::applyOverridesSafe();
