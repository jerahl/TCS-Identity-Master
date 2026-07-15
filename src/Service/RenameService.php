<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Db;
use PDO;

/**
 * The username/email rename workflow, triggered when a person's legal last name
 * changes. IDM mints the new username, emails the employee + their principal + IT
 * that the change is coming, and SCHEDULES the actual cutover for RENAME_NOTICE_DAYS
 * out (default 7). At cutover (handled by ScheduledEventRunner) the AD/Google
 * account is renamed, the OLD address is kept as a delivering alias for
 * RENAME_ALIAS_DAYS (default 90) with reminder emails, then removed.
 *
 * This is the ONE sanctioned exception to username immutability — a deliberate,
 * notified, scheduled change. It does not run automatically inside the reconciler.
 *
 * Pure-ish orchestration: DB + injected Mailer / ScheduledEventService, and an
 * optional read AdaxesService for the live-AD collision check. Time ($now) is
 * injected for deterministic tests.
 */
final class RenameService
{
    public const EVENT_CUTOVER  = 'username_cutover';
    public const EVENT_REMINDER = 'alias_reminder';
    public const EVENT_REMOVE   = 'alias_remove';

    private PDO $db;
    private Mailer $mailer;
    private ScheduledEventService $events;
    private AuditService $audit;
    private ?AdaxesService $read;
    private EmailTemplateService $templates;

    public function __construct(
        ?PDO $db = null,
        ?Mailer $mailer = null,
        ?ScheduledEventService $events = null,
        ?AuditService $audit = null,
        ?AdaxesService $read = null,
        ?EmailTemplateService $templates = null,
    ) {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
        $this->audit = $audit ?? new AuditService($this->db);
        $this->mailer = $mailer ?? new Mailer($this->db);
        $this->events = $events ?? new ScheduledEventService($this->db, $this->audit);
        $this->read = $read;
        $this->templates = $templates ?? new EmailTemplateService($this->db);
    }

    /**
     * Compute the rename plan for a person from their CURRENT (already-updated)
     * name vs their still-old assigned username. Returns null when there is
     * nothing to do: no locked identity yet (the create path handles that), or the
     * new username equals the current one.
     *
     * @return array{person_id:int, name:string, old_username:string, new_username:string,
     *               old_email:string, new_email:string, old_upn:string, new_upn:string}|null
     */
    public function plan(int $personId): ?array
    {
        $p = $this->person($personId);
        if ($p === null) {
            return null;
        }
        $oldUsername = trim((string) $p['username']);
        if ($oldUsername === '' || (int) $p['username_locked'] !== 1) {
            return null; // no assigned identity to rename
        }

        $newUsername = UsernameMinter::mint(
            (string) $p['first_name'],
            (string) $p['last_name'],
            fn(string $c): bool => $this->usernameTaken($c, $personId),
        );
        if (strcasecmp($newUsername, $oldUsername) === 0) {
            return null; // last-name change didn't move the username
        }

        $domain = trim((string) Config::get('AD_EMAIL_DOMAIN', 'tusc.k12.al.us'));
        $upnSuffix = trim((string) (Config::get('AD_UPN_SUFFIX', '') ?: $domain));
        return [
            'person_id'    => $personId,
            'name'         => trim((string) $p['first_name'] . ' ' . (string) $p['last_name']),
            'old_username' => $oldUsername,
            'new_username' => $newUsername,
            'old_email'    => trim((string) $p['email']) !== '' ? trim((string) $p['email']) : UsernameMinter::emailFor($oldUsername, $domain),
            'new_email'    => UsernameMinter::emailFor($newUsername, $domain),
            'old_upn'      => trim((string) $p['upn']) !== '' ? trim((string) $p['upn']) : UsernameMinter::emailFor($oldUsername, $upnSuffix),
            'new_upn'      => UsernameMinter::emailFor($newUsername, $upnSuffix),
        ];
    }

    /**
     * Approve a rename: schedule the cutover RENAME_NOTICE_DAYS out and email the
     * employee, their principal, and IT. Idempotent (dedupe on the target
     * username). Returns a result describing what happened.
     *
     * @param string|null $oldName optional previous full name (for the "changed from X" line)
     * @return array{ok:bool, scheduled:bool, note:string, plan:?array<string,mixed>, cutover_on:?string}
     */
    public function approve(int $personId, string $actor, ?string $oldName = null, ?string $now = null): array
    {
        $plan = $this->plan($personId);
        if ($plan === null) {
            return ['ok' => true, 'scheduled' => false, 'note' => 'No rename needed (no locked identity or the username is unchanged).', 'plan' => null, 'cutover_on' => null];
        }

        $now ??= gmdate('Y-m-d H:i:s');
        $noticeDays = max(0, (int) Config::get('RENAME_NOTICE_DAYS', '7'));
        $cutoverAt = self::plusDays($now, $noticeDays);
        $cutoverDate = substr($cutoverAt, 0, 10);

        $payload = $plan + ['old_name' => $oldName, 'cutover_date' => $cutoverDate];
        $dedupe = 'rename:' . $personId . ':' . strtolower($plan['new_username']);
        $eventId = $this->events->schedule(self::EVENT_CUTOVER, $cutoverAt, $payload, $personId, $actor, $dedupe);

        // Notice to the employee (their current/old address), principal, and IT.
        $to = [$plan['old_email']];
        $cc = array_merge($this->principalEmails($personId), $this->itEmails());
        $msg = $this->templates->render('rename_notice', self::noticeVars($plan, $oldName, $cutoverDate, substr($now, 0, 10)));
        $this->mailer->send($to, $msg['subject'], $msg['body'], $cc, $personId, 'rename_notice', $actor);

        $this->audit->lifecycle($personId, 'update',
            ['summary' => "Rename scheduled: {$plan['old_username']} → {$plan['new_username']} on {$cutoverDate} (notice sent)."], $actor);

        return ['ok' => true, 'scheduled' => true, 'note' => "Cutover scheduled for {$cutoverDate}; notice sent.", 'plan' => $plan, 'cutover_on' => $cutoverDate, 'event_id' => $eventId];
    }

    /**
     * The {placeholder} values for the rename-notice email. $fromDate (Y-m-d, the
     * day the notice is sent) anchors {days_remaining}; defaults to today.
     *
     * @param array<string,mixed> $plan
     * @return array<string,string|int>
     */
    public static function noticeVars(array $plan, ?string $oldName, string $cutoverDate, ?string $fromDate = null): array
    {
        return [
            'name'           => (string) $plan['name'],
            'old_name'       => ($oldName !== null && trim($oldName) !== '') ? trim($oldName) : 'the previous name on file',
            'old_username'   => (string) $plan['old_username'],
            'new_username'   => (string) $plan['new_username'],
            'old_email'      => (string) $plan['old_email'],
            'new_email'      => (string) $plan['new_email'],
            'cutover_date'   => $cutoverDate,
            'days_remaining' => self::daysUntil($cutoverDate, $fromDate),
            'alias_days'     => max(1, (int) Config::get('RENAME_ALIAS_DAYS', '90')),
        ];
    }

    /**
     * Whole calendar days from $fromDate (default today) until $targetDate, both
     * Y-m-d. Never negative — a past target reads as 0 ("today"). Used for the
     * {days_remaining} email placeholder.
     */
    public static function daysUntil(string $targetDate, ?string $fromDate = null): int
    {
        $target = strtotime(substr(trim($targetDate), 0, 10) . ' 00:00:00 UTC');
        $from   = strtotime(($fromDate !== null ? substr(trim($fromDate), 0, 10) : gmdate('Y-m-d')) . ' 00:00:00 UTC');
        if ($target === false || $from === false) {
            return 0;
        }
        return max(0, (int) round(($target - $from) / 86400));
    }

    // ---- lookups ------------------------------------------------------------

    /**
     * Email address of the person's school principal at their PRIMARY school, from
     * the golden record (the active person at that building whose assignment title
     * IS "Principal"). Preferred over a PowerSchool query — same data, always
     * present, no ODBC dependency. Empty when none is on file.
     *
     * Only the head principal: the title must START with "principal" (so
     * "Assistant/Associate/Vice/Deputy Principal" — which merely CONTAIN the word —
     * are excluded), and titles that start with "principal" but name a different
     * role ("Principal Secretary", "Principal's Clerk") are excluded too. At most
     * one recipient is returned; a broad substring match here previously cc'd every
     * assistant principal and office staffer at the building.
     *
     * @return list<string>
     */
    public function principalEmails(int $personId): array
    {
        $schoolId = $this->db->prepare('SELECT primary_school_id FROM person WHERE person_id = :id');
        $schoolId->execute([':id' => $personId]);
        $sid = $schoolId->fetchColumn();
        if ($sid === false || $sid === null) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT p.email
               FROM person p
               JOIN assignment a ON a.person_id = p.person_id
              WHERE a.school_id = :sid
                AND p.status = 'active'
                AND p.email IS NOT NULL AND p.email <> ''
                AND LOWER(TRIM(a.title)) LIKE 'principal%'
                AND LOWER(a.title) NOT LIKE '%assistant%'
                AND LOWER(a.title) NOT LIKE '%associate%'
                AND LOWER(a.title) NOT LIKE '%vice%'
                AND LOWER(a.title) NOT LIKE '%deputy%'
                AND LOWER(a.title) NOT LIKE '%secretary%'
                AND LOWER(a.title) NOT LIKE '%clerk%'
                AND p.person_id <> :self
              ORDER BY CASE WHEN LOWER(TRIM(a.title)) = 'principal' THEN 0 ELSE 1 END,
                       LENGTH(a.title), p.person_id
              LIMIT 1"
        );
        $stmt->execute([':sid' => (int) $sid, ':self' => $personId]);
        return array_values(array_filter(array_map(static fn($r) => trim((string) $r['email']), $stmt->fetchAll())));
    }

    /** @return list<string> IT recipients from config. */
    public function itEmails(): array
    {
        return Mailer::addresses((string) Config::get('IT_NOTIFY_EMAIL', ''));
    }

    // ---- helpers ------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function person(int $personId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT person_id, first_name, last_name, username, email, upn, username_locked, primary_school_id
               FROM person WHERE person_id = :id'
        );
        $stmt->execute([':id' => $personId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** A candidate collides against another person in the DB, or (if wired) live AD. */
    private function usernameTaken(string $candidate, int $selfId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM person WHERE LOWER(username) = LOWER(:u) AND person_id <> :self LIMIT 1');
        $stmt->execute([':u' => $candidate, ':self' => $selfId]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
        if ($this->read !== null && $this->read->configured()) {
            $ad = $this->read->search(['username' => $candidate]);
            return !$ad['ok'] || $ad['found'];
        }
        return false;
    }

    /** Add whole days to a 'Y-m-d H:i:s' timestamp without touching the clock. */
    private static function plusDays(string $ts, int $days): string
    {
        $base = strtotime($ts . ' UTC');
        if ($base === false) {
            $base = strtotime($ts) ?: 0;
        }
        return gmdate('Y-m-d H:i:s', $base + $days * 86400);
    }
}
