<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the dev demo fixture's shape so bin/seed_demo.php stays loadable and
 * each person has exactly one primary assignment (the invariant the app relies on).
 */
final class DemoFixtureTest extends TestCase
{
    /** @return array<int,array> */
    private function people(): array
    {
        return require dirname(__DIR__, 2) . '/db/seeds/demo_people.php';
    }

    public function testFixtureLoadsAndHasPeople(): void
    {
        $people = $this->people();
        self::assertNotEmpty($people);
        self::assertCount(11, $people);
    }

    public function testEveryPersonIsWellFormed(): void
    {
        $required = ['uuid', 'type', 'status', 'first', 'last', 'sources', 'assignments', 'lifecycle', 'sync'];
        $uuids = [];
        foreach ($this->people() as $p) {
            foreach ($required as $key) {
                self::assertArrayHasKey($key, $p, "missing {$key}");
            }
            self::assertSame(36, strlen($p['uuid']), 'uuid must be CHAR(36)');
            $uuids[] = $p['uuid'];

            $primaries = array_filter($p['assignments'], static fn($a) => !empty($a['primary']));
            self::assertCount(1, $primaries, "{$p['first']} {$p['last']} must have exactly one primary assignment");
        }
        self::assertSame(count($uuids), count(array_unique($uuids)), 'uuids must be unique');
    }

    public function testPendingPeopleHaveNoUsername(): void
    {
        foreach ($this->people() as $p) {
            if ($p['status'] === 'pending') {
                self::assertSame('', $p['username'], "pending person {$p['last']} should have no username");
            }
        }
    }
}
