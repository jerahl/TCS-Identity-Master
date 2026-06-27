<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testStaticAndParamRoutes(): void
    {
        $r = new Router();
        $r->get('/people', static fn() => 'list');
        $r->get('/people/{id}', static fn(array $p) => 'show:' . $p['id']);

        self::assertSame('list', $r->dispatch('GET', '/people'));
        self::assertSame('show:42', $r->dispatch('GET', '/people/42'));
        // Trailing slash normalizes.
        self::assertSame('list', $r->dispatch('GET', '/people/'));
    }

    public function testMethodMismatchAndNotFound(): void
    {
        $r = new Router();
        $r->get('/people', static fn() => 'list');
        $r->setNotFound(static fn() => '404');

        self::assertSame('404', $r->dispatch('POST', '/people'));
        self::assertSame('404', $r->dispatch('GET', '/nope'));
    }

    public function testParamDoesNotMatchAcrossSlash(): void
    {
        $r = new Router();
        $r->get('/people/{id}', static fn(array $p) => $p['id']);
        $r->setNotFound(static fn() => 'nf');

        // {id} matches one segment only.
        self::assertSame('nf', $r->dispatch('GET', '/people/1/extra'));
    }
}
