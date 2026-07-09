<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Mail\MailTransport;
use App\Service\Mail\NullMailTransport;
use App\Service\Mailer;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Mailer — composes, logs to email_outbox, and (when a transport is configured)
 * sends. The transport is injected, so this exercises the compose/log/outcome
 * path without sending real mail.
 */
final class MailerTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE email_outbox (
            id INTEGER PRIMARY KEY, person_id INTEGER, to_addr TEXT, cc_addr TEXT, subject TEXT, body TEXT,
            status TEXT DEFAULT 'queued', error TEXT, context TEXT, created_by TEXT, created_at TEXT, sent_at TEXT)");
        return $db;
    }

    public function testDisabledTransportQueuesButDoesNotSend(): void
    {
        $db = $this->db();
        $mailer = new Mailer($db, new NullMailTransport(), 'idm@tusc.k12.al.us');
        self::assertFalse($mailer->enabled());

        $res = $mailer->send('jdoe@tusc.k12.al.us', 'Hi', 'Body', context: 'test');
        self::assertFalse($res['ok']);
        $row = $db->query('SELECT * FROM email_outbox WHERE id = ' . (int) $res['id'])->fetch();
        self::assertSame('queued', $row['status']);        // logged, not lost
        self::assertSame('jdoe@tusc.k12.al.us', $row['to_addr']);
    }

    public function testConfiguredTransportSendsAndMarksSent(): void
    {
        $db = $this->db();
        $captured = null;
        $transport = new class ($captured) implements MailTransport {
            public function __construct(public mixed &$captured) {}
            public function send(array $message): array { $this->captured = $message; return ['ok' => true, 'error' => null]; }
            public function configured(): bool { return true; }
            public function name(): string { return 'fake'; }
        };
        $mailer = new Mailer($db, $transport, 'IDM <idm@tusc.k12.al.us>');

        $res = $mailer->send(['a@tusc.k12.al.us', 'a@tusc.k12.al.us'], 'Subj', 'Body', cc: 'b@tusc.k12.al.us', personId: 7, context: 'rename_notice', actor: 'sys');
        self::assertTrue($res['ok']);
        self::assertSame(['a@tusc.k12.al.us'], $captured['to']);       // deduped
        self::assertSame(['b@tusc.k12.al.us'], $captured['cc']);
        self::assertSame('IDM <idm@tusc.k12.al.us>', $captured['from']);

        $row = $db->query('SELECT * FROM email_outbox WHERE id = ' . (int) $res['id'])->fetch();
        self::assertSame('sent', $row['status']);
        self::assertSame(7, (int) $row['person_id']);
        self::assertSame('rename_notice', $row['context']);
        self::assertNotNull($row['sent_at']);
    }

    public function testTransportFailureMarksFailedWithError(): void
    {
        $db = $this->db();
        $transport = new class implements MailTransport {
            public function send(array $message): array { return ['ok' => false, 'error' => 'relay refused']; }
            public function configured(): bool { return true; }
            public function name(): string { return 'fake'; }
        };
        $res = (new Mailer($db, $transport, 'idm@x'))->send('a@x', 'S', 'B');
        self::assertFalse($res['ok']);
        $row = $db->query('SELECT * FROM email_outbox WHERE id = ' . (int) $res['id'])->fetch();
        self::assertSame('failed', $row['status']);
        self::assertSame('relay refused', $row['error']);
    }

    public function testNoRecipientsIsRejected(): void
    {
        $res = (new Mailer($this->db(), new NullMailTransport(), 'idm@x'))->send('', 'S', 'B');
        self::assertFalse($res['ok']);
        self::assertNull($res['id']);
    }

    public function testAddressNormalization(): void
    {
        self::assertSame(['a@x', 'b@x'], Mailer::addresses('a@x, b@x; a@x'));
        self::assertSame(['a@x'], Mailer::addresses(['a@x', '', ' a@x ']));
    }
}
