<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\View\Present;
use PHPUnit\Framework\TestCase;

final class PresentTest extends TestCase
{
    public function testStatusMapsKnownAndUnknown(): void
    {
        self::assertSame(['label' => 'Active', 'mod' => 'active'], Present::status('active'));
        self::assertSame(['label' => 'Terminated', 'mod' => 'terminated'], Present::status('terminated'));
        // Unknown status still yields a safe modifier (no missing CSS class).
        self::assertSame('pending', Present::status('weird')['mod']);
    }

    public function testTypeAndSourceLabels(): void
    {
        self::assertSame('Substitute', Present::type('sub'));
        self::assertSame('Active Directory', Present::sourceSystem('ad'));
        self::assertSame('Intern', Present::sourceSystem('intern_csv'));
    }

    public function testSyncModifier(): void
    {
        self::assertSame('ok', Present::syncMod('Success'));
        self::assertSame('fail', Present::syncMod('Fail'));
        self::assertSame('muted', Present::syncMod('Skipped'));
        self::assertSame('new', Present::syncMod(null));
    }

    public function testInitials(): void
    {
        self::assertSame('JM', Present::initials('Jennifer', 'Marsh'));
        self::assertSame('TH', Present::initials('Tomás', 'Herrera'));
    }
}
