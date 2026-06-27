<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testEscapesHtmlAndQuotes(): void
    {
        self::assertSame('&lt;b&gt;', e('<b>'));
        self::assertSame('a&amp;b', e('a&b'));
        self::assertSame('&quot;x&quot;', e('"x"'));
        self::assertSame('', e(null));
    }

    public function testUrlBuildsQuery(): void
    {
        self::assertSame('/people', url('/people'));
        self::assertSame('/people', url('people'));
        self::assertSame('/people?status=active', url('/people', ['status' => 'active']));
        self::assertStringContainsString('missing=1', url('/people', ['missing' => 1, 'q' => 'jo']));
    }
}
