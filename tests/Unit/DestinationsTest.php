<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Sync\Destinations;
use PHPUnit\Framework\TestCase;

/**
 * Per-destination provisioning view: always shows the four canonical
 * destinations (filling not-synced), maps OneSync's labels to them, and keeps
 * any extra destinations.
 */
final class DestinationsTest extends TestCase
{
    private function labels(array $rows): array
    {
        return array_map(static fn($r) => $r['label'], $rows);
    }

    public function testEmptyShowsAllFourCanonicalNotSynced(): void
    {
        $rows = Destinations::merge([]);
        self::assertSame(['Active Directory', 'Google Workspace', 'Raptor', 'PowerSchool'], $this->labels($rows));
        foreach ($rows as $r) {
            self::assertFalse($r['reported']);
            self::assertNull($r['last_status']);
        }
    }

    public function testReportedRowsOverlayInCanonicalOrder(): void
    {
        $reported = [
            ['destination' => 'Google Workspace', 'last_status' => 'Fail', 'last_action' => 'Edit', 'message' => 'quota'],
            ['destination' => 'Active Directory', 'last_status' => 'Success', 'last_action' => 'Add', 'message' => null],
        ];
        $rows = Destinations::merge($reported);

        self::assertSame(['Active Directory', 'Google Workspace', 'Raptor', 'PowerSchool'], $this->labels($rows));
        self::assertTrue($rows[0]['reported']);
        self::assertSame('Success', $rows[0]['last_status']);
        self::assertTrue($rows[1]['reported']);
        self::assertSame('Fail', $rows[1]['last_status']);
        self::assertFalse($rows[2]['reported'], 'Raptor not reported');
        self::assertFalse($rows[3]['reported'], 'PowerSchool not reported');
    }

    public function testOneSyncLabelAliasesMapToCanonical(): void
    {
        $rows = Destinations::merge([
            ['destination' => 'Faculty AD', 'last_status' => 'Success'],
            ['destination' => 'Google', 'last_status' => 'Success'],
        ]);
        self::assertTrue($rows[0]['reported'], '"Faculty AD" maps to Active Directory');
        self::assertTrue($rows[1]['reported'], '"Google" maps to Google Workspace');
    }

    public function testUnknownDestinationIsKept(): void
    {
        $rows = Destinations::merge([['destination' => 'Clever', 'last_status' => 'Success']]);
        self::assertCount(5, $rows, 'four canonical + one extra');
        self::assertSame('Clever', $rows[4]['label']);
        self::assertTrue($rows[4]['reported']);
    }
}
