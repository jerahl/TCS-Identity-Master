<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Import\PersonWriter;
use PDO;

/**
 * The concrete handlers the ScheduledEventRunner invokes for the rename/alias
 * lifecycle:
 *
 *  - username_cutover : rename the AD (and Google) account, keep the old address
 *    as a delivering alias, stamp the golden record, then schedule the alias
 *    reminders + removal.
 *  - alias_reminder   : email a heads-up before the old alias is removed.
 *  - alias_remove     : remove the old alias in AD + Google.
 *
 * The proxyAddresses math (withAlias/withoutAlias) is pure + static so it is
 * unit-tested directly; the orchestration calls the injected AD/Google/mail
 * writers. Every handler returns {ok, note}; a non-ok result makes the runner
 * retry (all operations are idempotent, so retries are safe).
 */
final class RenameEventHandlers
{
    private const ACTOR = 'system:scheduled_events';

    /** A well-formed objectGUID (the only kind we act on). */
    private const GUID_RE = '/^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$/';

    private EmailTemplateService $templates;

    public function __construct(
        private readonly PDO $db,
        private readonly AdaxesService $read,
        private readonly AdaxesWriter $writer,
        private readonly Mailer $mailer,
        private readonly ScheduledEventService $events,
        private readonly AuditService $audit,
        private readonly PersonWriter $people,
        private readonly ?GoogleWorkspaceService $google = null,
        ?EmailTemplateService $templates = null,
    ) {
        $this->templates = $templates ?? new EmailTemplateService($this->db);
    }

    /** event_type => handler, for the runner. */
    public function map(): array
    {
        return [
            RenameService::EVENT_CUTOVER  => fn(array $e, string $now) => $this->cutover($e, $now),
            RenameService::EVENT_REMINDER => fn(array $e, string $now) => $this->reminder($e, $now),
            RenameService::EVENT_REMOVE   => fn(array $e, string $now) => $this->remove($e, $now),
        ];
    }

    // ---- pure proxyAddresses helpers (unit-tested) --------------------------

    /**
     * The new proxyAddresses list after a rename: the new address becomes the
     * primary (SMTP:), the old address is kept as a secondary alias (smtp:), and
     * any other existing entries (further aliases, X500, …) are preserved. The old
     * primary is demoted to an alias; duplicates are collapsed case-insensitively.
     *
     * @param list<string> $current
     * @return list<string>
     */
    public static function withAlias(array $current, string $newPrimary, string $oldAddress): array
    {
        $out = ['SMTP:' . $newPrimary, 'smtp:' . $oldAddress];
        $seen = [strtolower($newPrimary) => true, strtolower($oldAddress) => true];
        foreach ($current as $entry) {
            $addr = self::addressOf($entry);
            if ($addr === '' || isset($seen[strtolower($addr)])) {
                continue;
            }
            $seen[strtolower($addr)] = true;
            // Demote any stray primary; keep non-smtp entries (e.g. X500) verbatim.
            $out[] = str_starts_with($entry, 'SMTP:') ? 'smtp:' . $addr : $entry;
        }
        return $out;
    }

    /**
     * The proxyAddresses list with the given address removed (any prefix).
     *
     * @param list<string> $current
     * @return list<string>
     */
    public static function withoutAlias(array $current, string $address): array
    {
        $drop = strtolower($address);
        $out = [];
        foreach ($current as $entry) {
            if (strtolower(self::addressOf($entry)) !== $drop) {
                $out[] = $entry;
            }
        }
        return array_values($out);
    }

    /** The bare address of a proxyAddresses entry ("smtp:a@b" -> "a@b"). */
    public static function addressOf(string $entry): string
    {
        $entry = trim($entry);
        $colon = strpos($entry, ':');
        // Only strip a known address-type prefix (smtp/x500/sip), not the "@"-less rest.
        if ($colon !== false && $colon <= 5 && preg_match('/^[a-zA-Z0-9]+$/', substr($entry, 0, $colon))) {
            return trim(substr($entry, $colon + 1));
        }
        return $entry;
    }

    // ---- handlers -----------------------------------------------------------

    /**
     * @param array<string,mixed> $event
     * @return array{ok:bool,note:string}
     */
    private function cutover(array $event, string $now): array
    {
        $p = ScheduledEventService::payloadOf($event);
        $pid = (int) ($event['person_id'] ?? 0);
        $guid = $this->linkedGuid($pid);
        if ($guid === null) {
            return ['ok' => false, 'note' => 'no linked objectGUID — cannot rename'];
        }
        [$oldEmail, $newEmail, $newUpn, $newUser] = [$p['old_email'] ?? '', $p['new_email'] ?? '', $p['new_upn'] ?? '', $p['new_username'] ?? ''];
        if ($newUser === '' || $newEmail === '') {
            return ['ok' => false, 'note' => 'incomplete rename payload'];
        }

        // 1) Rename the AD account (sAMAccountName + UPN + mail).
        $r = $this->writer->rename($guid, (string) $newUser, ['userPrincipalName' => (string) $newUpn, 'mail' => (string) $newEmail]);
        if (!$r['ok']) {
            return ['ok' => false, 'note' => 'AD rename failed: ' . $r['error']];
        }
        // 2) Keep the old address as a delivering alias in AD.
        $cur = $this->read->attributeValues($guid, 'proxyAddresses');
        $set = $this->writer->setProxyAddresses($guid, self::withAlias($cur['values'] ?? [], (string) $newEmail, (string) $oldEmail));
        if (!$set['ok']) {
            return ['ok' => false, 'note' => 'AD alias set failed: ' . $set['error']];
        }
        // 3) Google (best-effort, config-gated): rename + keep old as alias.
        $gnote = $this->googleRename((string) $oldEmail, (string) $newEmail);

        // 4) Golden record.
        $this->people->applyRename($pid, (string) $newUser, (string) $newEmail, (string) $newUpn, self::ACTOR);

        // 5) Schedule the alias removal + reminders, and confirm by email.
        $name = (string) ($p['name'] ?? '');
        $this->scheduleAliasLifecycle($pid, $guid, (string) $oldEmail, (string) $newEmail, $name, $now);
        $msg = $this->templates->render('rename_done', [
            'name'         => $name,
            'old_username' => (string) ($p['old_username'] ?? ''),
            'new_username' => (string) $newUser,
            'old_email'    => (string) $oldEmail,
            'new_email'    => (string) $newEmail,
            'alias_days'   => self::aliasDays(),
        ]);
        $this->mailer->send(array_merge([(string) $newEmail], $this->recipientsFor($pid)), $msg['subject'], $msg['body'], [], $pid, 'rename_done', self::ACTOR);

        return ['ok' => true, 'note' => "renamed {$p['old_username']}→{$newUser}" . ($gnote !== '' ? "; {$gnote}" : '')];
    }

    /**
     * @param array<string,mixed> $event
     * @return array{ok:bool,note:string}
     */
    private function reminder(array $event, string $now): array
    {
        $p = ScheduledEventService::payloadOf($event);
        $pid = (int) ($event['person_id'] ?? 0);
        $old = (string) ($p['old_email'] ?? '');
        $removeDate = (string) ($p['remove_date'] ?? '');
        $msg = $this->templates->render('alias_reminder', [
            'name'           => (string) ($p['name'] ?? ''),
            'old_email'      => $old,
            'new_email'      => (string) ($p['new_email'] ?? ''),
            'remove_date'    => $removeDate,
            'days_remaining' => RenameService::daysUntil($removeDate, substr($now, 0, 10)),
        ]);
        $this->mailer->send($this->recipientsFor($pid) ?: [(string) Config::get('IT_NOTIFY_EMAIL', '')], $msg['subject'], $msg['body'], [], $pid, 'alias_reminder', self::ACTOR);
        return ['ok' => true, 'note' => "reminder for {$old}"];
    }

    /**
     * @param array<string,mixed> $event
     * @return array{ok:bool,note:string}
     */
    private function remove(array $event, string $now): array
    {
        $p = ScheduledEventService::payloadOf($event);
        $pid = (int) ($event['person_id'] ?? 0);
        $guid = $this->linkedGuid($pid);
        $old = (string) ($p['old_email'] ?? '');
        if ($guid === null) {
            return ['ok' => false, 'note' => 'no linked objectGUID — cannot remove alias'];
        }
        $cur = $this->read->attributeValues($guid, 'proxyAddresses');
        $set = $this->writer->setProxyAddresses($guid, self::withoutAlias($cur['values'] ?? [], $old));
        if (!$set['ok']) {
            return ['ok' => false, 'note' => 'AD alias remove failed: ' . $set['error']];
        }
        $gnote = '';
        if ($this->google !== null && $this->google->configured()) {
            $g = $this->google->removeAlias((string) ($p['new_email'] ?? $old), $old);
            $gnote = $g['ok'] ? 'google alias removed' : ('google: ' . $g['error']);
        }
        $msg = $this->templates->render('alias_removed', [
            'name'      => (string) ($p['name'] ?? ''),
            'old_email' => $old,
            'new_email' => (string) ($p['new_email'] ?? ''),
        ]);
        $this->mailer->send($this->recipientsFor($pid) ?: [(string) Config::get('IT_NOTIFY_EMAIL', '')], $msg['subject'], $msg['body'], [], $pid, 'alias_removed', self::ACTOR);
        $this->audit->lifecycle($pid, 'update', ['summary' => "Old email alias {$old} removed after retention period."], self::ACTOR);
        return ['ok' => true, 'note' => "removed alias {$old}" . ($gnote !== '' ? "; {$gnote}" : '')];
    }

    // ---- internals ----------------------------------------------------------

    private function googleRename(string $oldEmail, string $newEmail): string
    {
        if ($this->google === null || !$this->google->configured()) {
            return '';
        }
        $upd = $this->google->updateUser($oldEmail, ['email' => $newEmail]);
        if (!$upd['ok']) {
            return 'google rename: ' . $upd['error'];
        }
        $alias = $this->google->addAlias($newEmail, $oldEmail);
        return $alias['ok'] ? 'google renamed + aliased' : ('google alias: ' . $alias['error']);
    }

    private function scheduleAliasLifecycle(int $pid, string $guid, string $oldEmail, string $newEmail, string $name, string $now): void
    {
        $aliasDays = self::aliasDays();
        $removeAt = gmdate('Y-m-d H:i:s', (strtotime($now . ' UTC') ?: 0) + $aliasDays * 86400);
        $removeDate = substr($removeAt, 0, 10);
        $payload = ['name' => $name, 'old_email' => $oldEmail, 'new_email' => $newEmail, 'guid' => $guid, 'remove_date' => $removeDate];

        $this->events->schedule(RenameService::EVENT_REMOVE, $removeAt, $payload, $pid, self::ACTOR, "aliasremove:{$pid}:" . strtolower($oldEmail));

        foreach (self::reminderDaysBefore() as $daysBefore) {
            $at = gmdate('Y-m-d H:i:s', (strtotime($removeAt . ' UTC') ?: 0) - $daysBefore * 86400);
            if (strtotime($at) > strtotime($now)) {
                $this->events->schedule(RenameService::EVENT_REMINDER, $at, $payload, $pid, self::ACTOR, "aliasremind:{$pid}:" . strtolower($oldEmail) . ":{$daysBefore}");
            }
        }
    }

    /** Recipients for confirmations/reminders: the school principal(s) + IT. @return list<string> */
    private function recipientsFor(int $pid): array
    {
        $rs = new RenameService($this->db);
        return array_values(array_filter(array_merge($rs->principalEmails($pid), $rs->itEmails())));
    }

    /** Well-formed, active AD objectGUID for the person, or null. */
    private function linkedGuid(int $personId): ?string
    {
        $stmt = $this->db->prepare("SELECT source_key FROM person_source_id WHERE person_id = :id AND system = 'ad' AND is_active = 1");
        $stmt->execute([':id' => $personId]);
        foreach ($stmt->fetchAll() as $r) {
            $k = trim((string) $r['source_key']);
            if (preg_match(self::GUID_RE, $k)) {
                return $k;
            }
        }
        return null;
    }

    private static function aliasDays(): int
    {
        return max(1, (int) Config::get('RENAME_ALIAS_DAYS', '90'));
    }

    /** @return list<int> */
    private static function reminderDaysBefore(): array
    {
        $raw = (string) Config::get('RENAME_ALIAS_REMINDER_DAYS', '14,3');
        $days = [];
        foreach (explode(',', $raw) as $d) {
            $d = (int) trim($d);
            if ($d > 0) {
                $days[] = $d;
            }
        }
        return $days;
    }
}
