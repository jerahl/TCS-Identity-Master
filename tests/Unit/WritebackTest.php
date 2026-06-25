<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\WritebackImporter;
use PHPUnit\Framework\TestCase;

/**
 * The username-immutability guardrail: decide() must never overwrite a locked
 * username with a different value, and must be idempotent.
 */
final class WritebackTest extends TestCase
{
    public function testAppliesWhenUnsetOrUnlocked(): void
    {
        self::assertSame('apply', WritebackImporter::decide(null, false, 'jdoe'));
        self::assertSame('apply', WritebackImporter::decide('', false, 'jdoe'));
        // Unlocked existing value may still be updated (locking happens on apply).
        self::assertSame('apply', WritebackImporter::decide('old', false, 'jdoe'));
    }

    public function testNoopWhenSameValue(): void
    {
        self::assertSame('noop', WritebackImporter::decide('jdoe', true, 'jdoe'));
        self::assertSame('noop', WritebackImporter::decide('jdoe', false, 'jdoe'));
    }

    public function testNeverOverwritesLockedWithDifferent(): void
    {
        self::assertSame('conflict', WritebackImporter::decide('jdoe', true, 'jsmith'));
    }

    public function testSkipsBlankIncoming(): void
    {
        self::assertSame('skip', WritebackImporter::decide('jdoe', true, ''));
        self::assertSame('skip', WritebackImporter::decide(null, false, null));
        self::assertSame('skip', WritebackImporter::decide(null, false, '   '));
    }
}
