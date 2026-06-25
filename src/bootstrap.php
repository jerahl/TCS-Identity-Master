<?php

declare(strict_types=1);

/**
 * Minimal bootstrap: makes the app runnable with OR without `composer install`.
 *
 *  - If Composer's autoloader exists, use it.
 *  - Otherwise register a tiny PSR-4 autoloader for App\ -> src/ so the CLI
 *    importers and migration runner work on a fresh checkout.
 *  - Load .env into the environment (without overwriting real env vars).
 */

$root = dirname(__DIR__);

$composerAutoload = $root . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
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
}

require $root . '/src/helpers.php';

\App\Config::load($root . '/.env');
