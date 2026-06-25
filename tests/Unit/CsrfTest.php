<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testCheckRequiresExactToken(): void
    {
        $_SESSION['_csrf'] = 'secret-token';
        self::assertTrue(Csrf::check('secret-token'));
        self::assertFalse(Csrf::check('wrong'));
        self::assertFalse(Csrf::check(null));
        self::assertFalse(Csrf::check(''));
    }

    public function testCheckFailsWhenNoTokenIssued(): void
    {
        self::assertFalse(Csrf::check('anything'), 'no session token => never valid');
    }

    public function testTokenIsStableAndVerifies(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !@session_start()) {
            self::markTestSkipped('cannot start a session in this environment');
        }
        $t1 = Csrf::token();
        $t2 = Csrf::token();
        self::assertSame($t1, $t2, 'token persists for the session');
        self::assertSame(64, strlen($t1), '32 random bytes => 64 hex chars');
        self::assertTrue(Csrf::check($t1));
    }
}
