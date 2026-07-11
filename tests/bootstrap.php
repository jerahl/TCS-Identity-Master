<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap. Prefer Composer's autoloader; fall back to a minimal PSR-4
 * autoloader so the suite runs on a fresh checkout before `composer install`.
 */

$root = dirname(__DIR__);

// Never sleep between Adaxes retry attempts during the test run.
putenv('ADAXES_RETRY_DELAY_MS=0');

require $root . '/src/helpers.php';

if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        foreach (['App\\' => '/src/', 'App\\Tests\\' => '/tests/'] as $prefix => $base) {
            if (str_starts_with($class, $prefix)) {
                $rel = substr($class, strlen($prefix));
                $file = $root . $base . str_replace('\\', '/', $rel) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    });
}
