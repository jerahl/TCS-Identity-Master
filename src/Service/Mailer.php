<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Db;
use App\Service\Mail\MailTransport;
use App\Service\Mail\NullMailTransport;
use App\Service\Mail\SendmailTransport;
use App\Service\Mail\SmtpTransport;
use PDO;

/**
 * Sends email and records every send in email_outbox. The transport (how mail
 * actually leaves) is pluggable — SMTP, the host sendmail, or a no-op "disabled"
 * transport (the default) — and injectable, so the compose/log path unit-tests
 * without sending anything.
 *
 * When mail is disabled the message is still logged ('queued') so nothing is
 * silently dropped; wiring a transport later lets those be retried.
 *
 * @phpstan-type SendResult array{ok:bool, error:?string, id:?int}
 */
final class Mailer
{
    private ?PDO $pdo;
    private MailTransport $transport;
    private string $from;

    /**
     * @param MailTransport|null $transport injected for tests; otherwise resolved from config
     */
    public function __construct(?PDO $db = null, ?MailTransport $transport = null, ?string $from = null)
    {
        $this->pdo = $db;
        $this->transport = $transport ?? self::transportFromConfig();
        $this->from = $from ?? (string) Config::get('MAIL_FROM', 'idm@' . Config::get('AD_EMAIL_DOMAIN', 'localhost'));
    }

    private function db(): PDO
    {
        return $this->pdo ??= Db::connect(Db::ROLE_APP);
    }

    public function enabled(): bool
    {
        return $this->transport->configured();
    }

    public function transportName(): string
    {
        return $this->transport->name();
    }

    /**
     * Compose, log, and (if a transport is configured) send an email.
     *
     * @param string|list<string> $to
     * @param string|list<string> $cc
     * @return SendResult
     */
    public function send(
        string|array $to,
        string $subject,
        string $body,
        string|array $cc = [],
        ?int $personId = null,
        ?string $context = null,
        ?string $actor = null
    ): array {
        $toList = self::addresses($to);
        $ccList = self::addresses($cc);
        if ($toList === []) {
            return ['ok' => false, 'error' => 'No recipients.', 'id' => null];
        }

        $id = $this->logQueued($toList, $ccList, $subject, $body, $personId, $context, $actor);

        if (!$this->transport->configured()) {
            // Left 'queued' — nothing lost; surface the reason to the caller.
            return ['ok' => false, 'error' => 'Mail is disabled — message queued but not sent.', 'id' => $id];
        }

        $res = $this->transport->send([
            'from'    => $this->from,
            'to'      => $toList,
            'cc'      => $ccList,
            'subject' => $subject,
            'body'    => $body,
        ]);

        $this->markOutcome($id, $res['ok'], $res['error']);
        return ['ok' => $res['ok'], 'error' => $res['error'], 'id' => $id];
    }

    // ---- outbox -------------------------------------------------------------

    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    private function logQueued(array $to, array $cc, string $subject, string $body, ?int $personId, ?string $context, ?string $actor): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO email_outbox (person_id, to_addr, cc_addr, subject, body, status, context, created_by)
             VALUES (:pid, :to, :cc, :subject, :body, :status, :context, :by)'
        );
        $stmt->execute([
            ':pid'     => $personId,
            ':to'      => implode(', ', $to),
            ':cc'      => $cc === [] ? null : implode(', ', $cc),
            ':subject' => $subject,
            ':body'    => $body,
            ':status'  => 'queued',
            ':context' => $context,
            ':by'      => $actor,
        ]);
        return (int) $this->db()->lastInsertId();
    }

    private function markOutcome(int $id, bool $ok, ?string $error): void
    {
        if ($ok) {
            $this->db()->prepare("UPDATE email_outbox SET status = 'sent', sent_at = CURRENT_TIMESTAMP, error = NULL WHERE id = :id")
                ->execute([':id' => $id]);
        } else {
            $this->db()->prepare("UPDATE email_outbox SET status = 'failed', error = :err WHERE id = :id")
                ->execute([':err' => $error === null ? null : mb_substr($error, 0, 1000), ':id' => $id]);
        }
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * Normalize a string or list of addresses into a clean, deduped list.
     *
     * @param string|list<string> $value
     * @return list<string>
     */
    public static function addresses(string|array $value): array
    {
        $items = is_array($value) ? $value : (preg_split('/[,;]+/', $value) ?: []);
        $out = [];
        foreach ($items as $a) {
            $a = trim((string) $a);
            if ($a !== '' && !in_array($a, $out, true)) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /** Resolve the configured transport (MAIL_TRANSPORT: null | sendmail | smtp). */
    private static function transportFromConfig(): MailTransport
    {
        if (!Config::bool('MAIL_ENABLED', false)) {
            return new NullMailTransport();
        }
        return match (strtolower((string) Config::get('MAIL_TRANSPORT', 'null'))) {
            'sendmail' => new SendmailTransport(),
            'smtp'     => new SmtpTransport(
                (string) Config::get('SMTP_HOST', ''),
                (int) Config::get('SMTP_PORT', '587'),
                (string) Config::get('SMTP_USER', ''),
                (string) Config::get('SMTP_PASS', ''),
                strtolower((string) Config::get('SMTP_SECURITY', 'tls')),
                (int) Config::get('SMTP_TIMEOUT', '15'),
                self::ehloName(),
            ),
            default => new NullMailTransport(),
        };
    }

    /** The hostname to announce in EHLO — from SMTP_EHLO, else the app host. */
    private static function ehloName(): string
    {
        $explicit = trim((string) Config::get('SMTP_EHLO', ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $host = parse_url((string) Config::get('APP_URL', ''), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'localhost';
    }
}
