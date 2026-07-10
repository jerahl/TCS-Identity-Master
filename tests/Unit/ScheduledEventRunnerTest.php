<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AuditService;
use App\Service\RenameEventHandlers;
use App\Service\ScheduledEventRunner;
use App\Service\ScheduledEventService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * ScheduledEventRunner dispatch — done on ok, retry-then-park on failure, and a
 * failure (retryable) when no handler is registered. Plus the pure proxyAddresses
 * math in RenameEventHandlers.
 */
final class ScheduledEventRunnerTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE scheduled_event (id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT, run_at TEXT,
            payload TEXT, status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0, last_error TEXT, dedupe_key TEXT,
            created_by TEXT, created_at TEXT, updated_at TEXT)");
        $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    public function testDispatchesDoneAndFailedAndSkipsFuture(): void
    {
        $db = $this->db();
        $events = new ScheduledEventService($db, new AuditService($db));
        $okId   = $events->schedule('ok_type', '2026-07-10 09:00:00', ['a' => 1], 1, 'sys');
        $failId = $events->schedule('fail_type', '2026-07-10 09:00:00', null, 1, 'sys');
        $events->schedule('ok_type', '2026-12-01 09:00:00', null, 1, 'sys'); // not due yet

        $seen = [];
        $handlers = [
            'ok_type'   => function (array $e, string $now) use (&$seen) { $seen[] = (int) $e['id']; return ['ok' => true, 'note' => 'did it']; },
            'fail_type' => fn(array $e, string $now) => ['ok' => false, 'note' => 'nope'],
        ];

        $res = (new ScheduledEventRunner($events, $handlers))->run('2026-07-10 09:00:00');

        self::assertSame(2, $res['processed']);
        self::assertSame(1, $res['done']);
        self::assertSame(1, $res['failed']);
        self::assertSame([$okId], $seen); // future one not dispatched

        self::assertSame('done', $db->query("SELECT status FROM scheduled_event WHERE id = {$okId}")->fetchColumn());
        // The failed one stays pending (retryable) with the error recorded.
        self::assertSame('pending', $db->query("SELECT status FROM scheduled_event WHERE id = {$failId}")->fetchColumn());
        self::assertSame('nope', $db->query("SELECT last_error FROM scheduled_event WHERE id = {$failId}")->fetchColumn());
    }

    public function testNoHandlerFailsGracefully(): void
    {
        $db = $this->db();
        $events = new ScheduledEventService($db, new AuditService($db));
        $id = $events->schedule('unknown', '2026-07-10 09:00:00', null, 1, 'sys');
        $res = (new ScheduledEventRunner($events, []))->run('2026-07-10 09:00:00');
        self::assertSame(1, $res['failed']);
        self::assertStringContainsString('No handler', (string) $db->query("SELECT last_error FROM scheduled_event WHERE id = {$id}")->fetchColumn());
    }

    public function testThrowingHandlerIsCaughtAndMarkedFailed(): void
    {
        $db = $this->db();
        $events = new ScheduledEventService($db, new AuditService($db));
        $events->schedule('boom', '2026-07-10 09:00:00', null, 1, 'sys');
        $handlers = ['boom' => function () { throw new \RuntimeException('kaboom'); }];
        $res = (new ScheduledEventRunner($events, $handlers))->run('2026-07-10 09:00:00');
        self::assertSame(1, $res['failed']);
    }

    // ---- pure proxyAddresses helpers ---------------------------------------

    public function testWithAliasMakesNewPrimaryAndKeepsOldAsSecondary(): void
    {
        $out = RenameEventHandlers::withAlias(['SMTP:jsmith@x', 'smtp:jsmith.alt@x', 'X500:/o=abc'], 'jjones@x', 'jsmith@x');
        self::assertSame('SMTP:jjones@x', $out[0]);          // new primary
        self::assertContains('smtp:jsmith@x', $out);         // old address kept as alias
        self::assertContains('smtp:jsmith.alt@x', $out);     // other alias preserved
        self::assertContains('X500:/o=abc', $out);           // non-smtp entry preserved
        // No duplicate of the old primary as a second SMTP:.
        self::assertSame(1, count(array_filter($out, static fn($e) => str_starts_with($e, 'SMTP:'))));
    }

    public function testWithoutAliasRemovesTheAddressAnyPrefix(): void
    {
        $out = RenameEventHandlers::withoutAlias(['SMTP:jjones@x', 'smtp:jsmith@x'], 'jsmith@x');
        self::assertSame(['SMTP:jjones@x'], $out);
    }

    public function testAddressOf(): void
    {
        self::assertSame('a@b.com', RenameEventHandlers::addressOf('smtp:a@b.com'));
        self::assertSame('a@b.com', RenameEventHandlers::addressOf('SMTP:a@b.com'));
        self::assertSame('a@b.com', RenameEventHandlers::addressOf('a@b.com'));
    }
}
