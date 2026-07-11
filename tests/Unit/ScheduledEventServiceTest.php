<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AuditService;
use App\Service\ScheduledEventService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * ScheduledEventService — the delayed-events queue. Time is injected, so due-ness,
 * dedupe, retry/park, and cancellation are all deterministic over sqlite.
 */
final class ScheduledEventServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE scheduled_event (
            id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT, run_at TEXT, payload TEXT,
            status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0, last_error TEXT,
            dedupe_key TEXT, created_by TEXT, created_at TEXT, updated_at TEXT)");
        $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    private function svc(PDO $db): ScheduledEventService
    {
        return new ScheduledEventService($db, new AuditService($db));
    }

    public function testSchedulesAndSurfacesOnlyDueEvents(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->schedule('username_cutover', '2026-07-16 09:00:00', ['new' => 'jdoe'], 1, 'tester');
        $svc->schedule('alias_remove', '2026-10-14 09:00:00', null, 1, 'tester');

        // Only the first is due at this instant.
        $due = $svc->due('2026-07-16 09:00:00');
        self::assertCount(1, $due);
        self::assertSame('username_cutover', $due[0]['event_type']);
        self::assertSame(['new' => 'jdoe'], ScheduledEventService::payloadOf($due[0]));

        // Later, both are due.
        self::assertCount(2, $svc->due('2026-10-14 09:00:00'));
    }

    public function testDedupeReturnsExistingPendingEvent(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $a = $svc->schedule('username_cutover', '2026-07-16 09:00:00', null, 1, 'tester', 'rename:1:v2');
        $b = $svc->schedule('username_cutover', '2026-07-16 09:00:00', null, 1, 'tester', 'rename:1:v2');
        self::assertSame($a, $b);
        self::assertCount(1, $svc->due('2026-07-16 09:00:00'));
    }

    public function testMarkDone(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $id = $svc->schedule('x', '2026-01-01 00:00:00', null, 1, 'tester');
        $svc->markDone($id);
        self::assertCount(0, $svc->due('2026-01-01 00:00:00'));
        self::assertSame('done', $db->query("SELECT status FROM scheduled_event WHERE id = {$id}")->fetchColumn());
    }

    public function testFailureRetriesThenParksAtMaxAttempts(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $id = $svc->schedule('x', '2026-01-01 00:00:00', null, 1, 'tester');

        for ($i = 0; $i < ScheduledEventService::MAX_ATTEMPTS - 1; $i++) {
            $svc->markFailed($id, 'boom');
            self::assertSame('pending', $db->query("SELECT status FROM scheduled_event WHERE id = {$id}")->fetchColumn(), "attempt {$i}");
        }
        $svc->markFailed($id, 'boom'); // the MAX-th
        self::assertSame('failed', $db->query("SELECT status FROM scheduled_event WHERE id = {$id}")->fetchColumn());
        self::assertCount(0, $svc->due('2026-01-01 00:00:00')); // no longer pending
    }

    public function testCancelPendingForPerson(): void
    {
        $db = $this->db();
        $svc = $this->svc($db);
        $svc->schedule('username_cutover', '2026-07-16 09:00:00', null, 5, 'tester');
        $svc->schedule('alias_remove', '2026-10-14 09:00:00', null, 5, 'tester');
        $svc->schedule('x', '2026-07-16 09:00:00', null, 9, 'tester'); // different person

        $n = $svc->cancelPending(5, 'tester');
        self::assertSame(2, $n);
        self::assertCount(1, $svc->due('2027-01-01 00:00:00')); // only person 9's remains
    }
}
