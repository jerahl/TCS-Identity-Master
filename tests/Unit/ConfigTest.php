<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config;
use PHPUnit\Framework\TestCase;

/**
 * Milestone-1 smoke tests: the config loader resolves real env vars over .env,
 * applies defaults, and coerces types. (The matcher gets its thorough suite in
 * Milestone 3.)
 */
final class ConfigTest extends TestCase
{
    public function testRealEnvWinsAndDefaultsApply(): void
    {
        putenv('IDM_TEST_KEY=from-env');
        Config::load('/nonexistent/.env'); // no file: defaults + real env only

        self::assertSame('from-env', Config::get('IDM_TEST_KEY'));
        self::assertSame('fallback', Config::get('IDM_MISSING_KEY', 'fallback'));
        self::assertNull(Config::get('IDM_MISSING_KEY'));

        putenv('IDM_TEST_KEY'); // unset
    }

    public function testBoolAndIntCoercion(): void
    {
        putenv('IDM_FLAG=true');
        putenv('IDM_NUM=90');

        self::assertTrue(Config::bool('IDM_FLAG'));
        self::assertFalse(Config::bool('IDM_ABSENT', false));
        self::assertSame(90, Config::int('IDM_NUM'));
        self::assertSame(5, Config::int('IDM_ABSENT', 5));

        putenv('IDM_FLAG');
        putenv('IDM_NUM');
    }
}
