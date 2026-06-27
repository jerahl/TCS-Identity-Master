<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\PersonWriter;
use PHPUnit\Framework\TestCase;

/**
 * Status transitions map to the right lifecycle event when a record is edited.
 */
final class StatusEventTest extends TestCase
{
    public function testTransitions(): void
    {
        self::assertSame('disable', PersonWriter::statusEventType('active', 'disabled'));
        self::assertSame('terminate', PersonWriter::statusEventType('active', 'terminated'));
        self::assertSame('enable', PersonWriter::statusEventType('disabled', 'active'));
        self::assertSame('enable', PersonWriter::statusEventType('pending', 'active'));
    }

    public function testNoChangeOrOtherIsUpdate(): void
    {
        self::assertSame('update', PersonWriter::statusEventType('active', 'active'));
        self::assertSame('update', PersonWriter::statusEventType('active', 'pending'));
    }
}
