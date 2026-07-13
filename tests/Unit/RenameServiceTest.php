<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AuditService;
use App\Service\Mail\MailTransport;
use App\Service\Mailer;
use App\Service\RenameService;
use App\Service\ScheduledEventService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * RenameService — plans the username/email change from a last-name change,
 * schedules the cutover, and emails the employee + principal + IT. sqlite +
 * injected mailer transport; no real AD or mail.
 */
final class RenameServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person (person_id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
            username TEXT UNIQUE, email TEXT UNIQUE, upn TEXT, username_locked INTEGER DEFAULT 0,
            status TEXT, primary_school_id INTEGER)');
        $db->exec('CREATE TABLE assignment (id INTEGER PRIMARY KEY, person_id INTEGER, school_id INTEGER, title TEXT)');
        $db->exec("CREATE TABLE scheduled_event (id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT, run_at TEXT,
            payload TEXT, status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0, last_error TEXT, dedupe_key TEXT,
            created_by TEXT, created_at TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE email_outbox (id INTEGER PRIMARY KEY, person_id INTEGER, to_addr TEXT, cc_addr TEXT,
            subject TEXT, body TEXT, status TEXT DEFAULT 'queued', error TEXT, context TEXT, created_by TEXT, created_at TEXT, sent_at TEXT)");
        $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE lifecycle_event (id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT,
            detail TEXT, actor TEXT, occurred_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    /** A mailer whose transport records the last message. */
    private function mailer(PDO $db, ?array &$captured): Mailer
    {
        $transport = new class ($captured) implements MailTransport {
            public function __construct(public mixed &$captured) {}
            public function send(array $message): array { $this->captured = $message; return ['ok' => true, 'error' => null]; }
            public function configured(): bool { return true; }
            public function name(): string { return 'fake'; }
        };
        return new Mailer($db, $transport, 'idm@tusc.k12.al.us');
    }

    private function service(PDO $db, Mailer $mailer): RenameService
    {
        $audit = new AuditService($db);
        return new RenameService($db, $mailer, new ScheduledEventService($db, $audit), $audit, null);
    }

    private function seedLocked(PDO $db): void
    {
        // John Smith, locked as jsmith at school 30; last name already changed to Jones.
        $db->exec("INSERT INTO person (person_id, first_name, last_name, username, email, upn, username_locked, status, primary_school_id)
                   VALUES (1, 'John', 'Jones', 'jsmith', 'jsmith@tusc.k12.al.us', 'jsmith@tusc.k12.al.us', 1, 'active', 30)");
    }

    public function testPlanComputesNewUsernameOnLastNameChange(): void
    {
        $db = $this->db();
        $this->seedLocked($db);
        $captured = null;
        $plan = $this->service($db, $this->mailer($db, $captured))->plan(1);

        self::assertNotNull($plan);
        self::assertSame('jsmith', $plan['old_username']);
        self::assertSame('jjones', $plan['new_username']);
        self::assertSame('jjones@tusc.k12.al.us', $plan['new_email']);
    }

    public function testPlanIsNullWhenUsernameUnchangedOrUnlocked(): void
    {
        $db = $this->db();
        // Locked, but name still yields the same username.
        $db->exec("INSERT INTO person (person_id, first_name, last_name, username, email, upn, username_locked, status)
                   VALUES (1, 'John', 'Smith', 'jsmith', 'jsmith@tusc.k12.al.us', 'jsmith@tusc.k12.al.us', 1, 'active')");
        // Not locked (never assigned) → create path handles it, not rename.
        $db->exec("INSERT INTO person (person_id, first_name, last_name, username, email, upn, username_locked, status)
                   VALUES (2, 'Jane', 'Doe', NULL, NULL, NULL, 0, 'pending')");
        $captured = null;
        $svc = $this->service($db, $this->mailer($db, $captured));

        self::assertNull($svc->plan(1));
        self::assertNull($svc->plan(2));
    }

    public function testApproveSchedulesCutoverAndEmailsEmployeePrincipalAndIt(): void
    {
        putenv('IT_NOTIFY_EMAIL=it@tusc.k12.al.us');
        try {
            $db = $this->db();
            $this->seedLocked($db);
            // Principal at the same school.
            $db->exec("INSERT INTO person (person_id, first_name, last_name, username, email, upn, username_locked, status, primary_school_id)
                       VALUES (2, 'Prin', 'Cipal', 'pcipal', 'principal@tusc.k12.al.us', 'principal@tusc.k12.al.us', 1, 'active', 30)");
            $db->exec("INSERT INTO assignment (person_id, school_id, title) VALUES (2, 30, 'Principal - High')");

            $captured = null;
            $mailer = $this->mailer($db, $captured);
            $svc = $this->service($db, $mailer);

            $res = $svc->approve(1, 'admin', 'John Smith', '2026-07-10 12:00:00');

            self::assertTrue($res['scheduled']);
            self::assertSame('2026-07-17', $res['cutover_on']); // +7 days

            // A cutover event was scheduled for that date.
            $ev = $db->query("SELECT * FROM scheduled_event WHERE event_type = 'username_cutover'")->fetch();
            self::assertNotFalse($ev);
            self::assertStringStartsWith('2026-07-17', (string) $ev['run_at']);
            $payload = json_decode((string) $ev['payload'], true);
            self::assertSame('jjones', $payload['new_username']);

            // The notice email went to the employee (old address), cc principal + IT.
            self::assertSame(['jsmith@tusc.k12.al.us'], $captured['to']);
            self::assertContains('principal@tusc.k12.al.us', $captured['cc']);
            self::assertContains('it@tusc.k12.al.us', $captured['cc']);
            self::assertStringContainsString('John Smith to John Jones', $captured['body']);
            self::assertStringContainsString('jsmith  ->  jjones', $captured['body']);
            // {days_remaining}: notice sent 07-10, cutover 07-17 → 7 days.
            self::assertStringContainsString('In 7 days', $captured['body']);
        } finally {
            putenv('IT_NOTIFY_EMAIL');
        }
    }

    public function testNoticeVarsIncludesDaysRemaining(): void
    {
        $plan = [
            'name' => 'John Jones', 'old_username' => 'jsmith', 'new_username' => 'jjones',
            'old_email' => 'jsmith@tusc.k12.al.us', 'new_email' => 'jjones@tusc.k12.al.us',
        ];
        $vars = RenameService::noticeVars($plan, 'John Smith', '2026-07-17', '2026-07-10');
        self::assertSame(7, $vars['days_remaining']);
        self::assertSame('2026-07-17', $vars['cutover_date']);
    }

    public function testDaysUntilComputesWholeCalendarDaysAndNeverNegative(): void
    {
        self::assertSame(7, RenameService::daysUntil('2026-07-17', '2026-07-10'));
        self::assertSame(1, RenameService::daysUntil('2026-07-11', '2026-07-10'));
        self::assertSame(0, RenameService::daysUntil('2026-07-10', '2026-07-10'));
        // A target already in the past reads as 0, not negative.
        self::assertSame(0, RenameService::daysUntil('2026-07-01', '2026-07-10'));
    }

    public function testApproveIsIdempotentViaDedupe(): void
    {
        $db = $this->db();
        $this->seedLocked($db);
        $captured = null;
        $svc = $this->service($db, $this->mailer($db, $captured));
        $svc->approve(1, 'admin', null, '2026-07-10 12:00:00');
        $svc->approve(1, 'admin', null, '2026-07-10 12:00:00');
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM scheduled_event WHERE event_type = 'username_cutover'")->fetchColumn());
    }
}
