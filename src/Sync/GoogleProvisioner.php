<?php

declare(strict_types=1);

namespace App\Sync;

use App\Db;
use App\Import\PersonWriter;
use App\Import\SyncStatusImporter;
use App\Service\AuditService;
use App\Service\GoogleWorkspaceService;
use PDO;

/**
 * Orchestrates a single direct-to-Google provisioning action against the golden
 * record — the shared engine behind BOTH the per-person record-page buttons and
 * the batch GoogleSync job, so both behave identically.
 *
 * For each action it: (1) correlates the person to their live Google account
 * (OneSync-style), (2) calls the GoogleWorkspaceService write, and (3) reflects
 * the outcome back into the same tables OneSync writes — the (system='google')
 * crosswalk, account_sync_status (via SyncStatusImporter::applyEvent, so the
 * dashboard/person page show it), and audit_log + lifecycle_event — so a direct
 * write is indistinguishable, downstream, from a OneSync-reported one.
 *
 * "Disable" is a SUSPEND (reversible). The golden person.status is NOT mutated
 * here — the golden record is the source of truth that *drives* provisioning,
 * not the other way round; a per-person suspend is a destination override that
 * the batch will not silently undo (GoogleSync never auto-restores).
 */
final class GoogleProvisioner
{
    /** Canonical destination label/type mirrored into account_sync_status. */
    public const DESTINATION = 'Google Workspace';
    public const DEST_TYPE = 'GSuite';

    private PDO $db;
    private GoogleWorkspaceService $google;
    private AuditService $audit;
    private PersonWriter $writer;
    private SyncStatusImporter $status;

    public function __construct(
        ?PDO $db = null,
        ?GoogleWorkspaceService $google = null,
        ?AuditService $audit = null,
        ?PersonWriter $writer = null,
        ?SyncStatusImporter $status = null,
    ) {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
        $this->google = $google ?? new GoogleWorkspaceService();
        $this->audit = $audit ?? new AuditService($this->db);
        $this->writer = $writer ?? new PersonWriter($this->db, $this->audit);
        $this->status = $status ?? new SyncStatusImporter($this->db);
    }

    public function service(): GoogleWorkspaceService
    {
        return $this->google;
    }

    /**
     * The per-person entry point (record-page buttons). Loads the person, runs
     * the requested action, and returns a UI-friendly result.
     *
     * @param string $action one of: link | create | push | suspend | restore
     * @return array{ok:bool, message:string, action:string, googleId:?string}
     */
    public function provision(int $personId, string $action, string $actor, bool $dryRun = false): array
    {
        $person = $this->loadPerson($personId);
        if ($person === null) {
            return self::result(false, 'That person no longer exists.', $action);
        }
        $sourceIds = $this->loadSourceIds($personId);
        $ou = $this->orgUnitFor($person);

        $corr = $this->google->correlate($person, $sourceIds);
        if (!$corr['ok'] && !$corr['configured']) {
            return self::result(false, (string) ($corr['error'] ?? 'Direct Google provisioning is off.'), $action);
        }
        if (!$corr['ok']) {
            return self::result(false, 'Could not reach Google: ' . (string) ($corr['error'] ?? 'unknown error'), $action);
        }

        return match ($action) {
            'link'    => $this->doLink($person, $corr, $actor, $dryRun),
            'create'  => $this->doCreate($person, $sourceIds, $corr, $ou, $actor, $dryRun),
            'push'    => $this->doPush($person, $corr, $ou, $actor, $dryRun),
            'suspend' => $this->doSuspend($person, $corr, $actor, $dryRun),
            'restore' => $this->doRestore($person, $corr, $actor, $dryRun),
            default   => self::result(false, "Unknown Google action '{$action}'.", $action),
        };
    }

    // ---- individual actions -------------------------------------------------

    /** Attach the crosswalk id for a correlated account (admin confirmed the match). */
    private function doLink(array $person, array $corr, string $actor, bool $dryRun): array
    {
        if (empty($corr['found']) || ($corr['googleId'] ?? '') === '') {
            return self::result(false, 'No Google account matched this person to link.', 'link');
        }
        $googleId = (string) $corr['googleId'];
        $email = (string) ($corr['primaryEmail'] ?? '');
        if ($dryRun) {
            return self::result(true, "Would link Google account {$email} ({$googleId}).", 'link', $googleId);
        }
        $this->linkCrosswalk((int) $person['person_id'], $googleId, $person['person_uuid'], $actor, $email, existing: $corr['suspended']);
        return self::result(true, "Linked Google account {$email}.", 'link', $googleId);
    }

    private function doCreate(array $person, array $sourceIds, array $corr, ?string $ou, string $actor, bool $dryRun): array
    {
        if (!empty($corr['found'])) {
            $email = (string) ($corr['primaryEmail'] ?? '');
            return self::result(false, "A Google account already exists ({$email}) — use Link/Push instead of Create.", 'create', $corr['googleId'] ?? null);
        }
        if (trim((string) ($person['email'] ?? '')) === '') {
            return self::result(false, 'No golden email on file — set the primary email before creating in Google.', 'create');
        }
        if ($dryRun) {
            return self::result(true, "Would create Google account {$person['email']}.", 'create');
        }

        $res = $this->google->createUser($person, $ou);
        $this->reflect($person, 'Add', $res, $actor);
        if (!$res['ok']) {
            return self::result(false, 'Create failed: ' . (string) $res['error'], 'create');
        }
        $googleId = (string) ($res['googleId'] ?? '');
        if ($googleId !== '') {
            $this->linkCrosswalk((int) $person['person_id'], $googleId, $person['person_uuid'], $actor, (string) $res['primaryEmail']);
        }
        return self::result(true, "Created Google account {$res['primaryEmail']}.", 'create', $googleId);
    }

    private function doPush(array $person, array $corr, ?string $ou, string $actor, bool $dryRun): array
    {
        $key = self::accountKey($corr);
        if ($key === null) {
            return self::result(false, 'No linked Google account to push changes to.', 'push');
        }
        if (($guard = self::guardNameOnly($corr, 'push')) !== null) {
            return $guard;
        }
        if ($dryRun) {
            return self::result(true, "Would push golden-record changes to {$corr['primaryEmail']}.", 'push', $corr['googleId'] ?? null);
        }
        $res = $this->google->updateUser($key, $person, $ou);
        $this->reflect($person, 'Edit', $res, $actor);
        if (!$res['ok']) {
            return self::result(false, 'Push failed: ' . (string) $res['error'], 'push');
        }
        $this->ensureCrosswalk($person, $corr, $actor);
        return self::result(true, "Pushed golden-record changes to {$res['primaryEmail']}.", 'push', $res['googleId']);
    }

    private function doSuspend(array $person, array $corr, string $actor, bool $dryRun): array
    {
        $key = self::accountKey($corr);
        if ($key === null) {
            return self::result(false, 'No linked Google account to suspend.', 'suspend');
        }
        if (($guard = self::guardNameOnly($corr, 'suspend')) !== null) {
            return $guard;
        }
        if (!empty($corr['found']) && $corr['suspended'] === true) {
            return self::result(true, 'Google account is already suspended — no change.', 'suspend', $corr['googleId'] ?? null);
        }
        if ($dryRun) {
            return self::result(true, "Would suspend {$corr['primaryEmail']}.", 'suspend', $corr['googleId'] ?? null);
        }
        $res = $this->google->suspendUser($key);
        $this->reflect($person, 'Disable', $res, $actor);
        if (!$res['ok']) {
            return self::result(false, 'Suspend failed: ' . (string) $res['error'], 'suspend');
        }
        $this->ensureCrosswalk($person, $corr, $actor);
        $this->audit->lifecycle((int) $person['person_id'], 'disable',
            ['summary' => 'Suspended Google Workspace account (direct): ' . (string) $res['primaryEmail']], $actor);
        return self::result(true, "Suspended {$res['primaryEmail']}.", 'suspend', $res['googleId']);
    }

    private function doRestore(array $person, array $corr, string $actor, bool $dryRun): array
    {
        $key = self::accountKey($corr);
        if ($key === null) {
            return self::result(false, 'No linked Google account to restore.', 'restore');
        }
        if (($guard = self::guardNameOnly($corr, 'restore')) !== null) {
            return $guard;
        }
        if (!empty($corr['found']) && $corr['suspended'] === false) {
            return self::result(true, 'Google account is already active — no change.', 'restore', $corr['googleId'] ?? null);
        }
        if ($dryRun) {
            return self::result(true, "Would restore {$corr['primaryEmail']}.", 'restore', $corr['googleId'] ?? null);
        }
        $res = $this->google->restoreUser($key);
        $this->reflect($person, 'Enable', $res, $actor);
        if (!$res['ok']) {
            return self::result(false, 'Restore failed: ' . (string) $res['error'], 'restore');
        }
        $this->ensureCrosswalk($person, $corr, $actor);
        $this->audit->lifecycle((int) $person['person_id'], 'enable',
            ['summary' => 'Restored Google Workspace account (direct): ' . (string) $res['primaryEmail']], $actor);
        return self::result(true, "Restored {$res['primaryEmail']}.", 'restore', $res['googleId']);
    }

    // ---- persistence helpers ------------------------------------------------

    /**
     * Reflect a write outcome into account_sync_status + account_sync_event, via
     * the same SyncStatusImporter::applyEvent() OneSync's importer uses — so the
     * dashboard and person page show the direct write exactly like a reported one.
     *
     * @param array<string,mixed> $person
     * @param array{ok:bool,error:?string,action:string,googleId:?string,primaryEmail:?string,suspended:?bool,attributes:array<string,string>} $res
     */
    private function reflect(array $person, string $action, array $res, string $actor): void
    {
        $this->status->applyEvent([
            'uniqueId'     => (string) $person['person_uuid'],
            'destination'  => self::DESTINATION,
            'destType'     => self::DEST_TYPE,
            'action'       => $action,
            'actionStatus' => $res['ok'] ? 'Success' : 'Fail',
            'message'      => $res['ok']
                ? 'Direct provisioning by ' . $actor
                : mb_substr('Direct provisioning failed: ' . (string) $res['error'], 0, 1000),
            'timestamp'    => gmdate('c'),
        ]);
    }

    /** Attach the (system='google') crosswalk id + note it on the timeline. */
    private function linkCrosswalk(int $personId, string $googleId, string $uuid, string $actor, string $email, ?bool $existing = null): void
    {
        $this->writer->attachSourceId($personId, 'google', $googleId, $actor);
        $this->audit->lifecycle($personId, 'update',
            ['summary' => 'Linked Google Workspace account (direct): ' . ($email !== '' ? $email : $googleId)], $actor);
        // Record a NoChange status row so the destination shows as provisioned.
        $this->status->applyEvent([
            'uniqueId' => $uuid, 'destination' => self::DESTINATION, 'destType' => self::DEST_TYPE,
            'action' => 'NoChange', 'actionStatus' => 'Success',
            'message' => 'Linked to existing Google account by ' . $actor, 'timestamp' => gmdate('c'),
        ]);
    }

    /** Attach the crosswalk id if the account isn't linked yet (post-write). */
    private function ensureCrosswalk(array $person, array $corr, string $actor): void
    {
        $googleId = (string) ($corr['googleId'] ?? '');
        if ($googleId === '' || ($corr['by'] ?? null) === 'id') {
            return; // nothing to link, or already linked by crosswalk id
        }
        $this->writer->attachSourceId((int) $person['person_id'], 'google', $googleId, $actor);
    }

    /**
     * Refuse a mutate (push/suspend/restore) on a name-only correlation that
     * hasn't been confirmed + linked — the match is a suggestion, never trusted
     * for writes until an admin links it. Returns a result to short-circuit with,
     * or null to proceed. (Strong-key matches set auto=true; a crosswalk-linked
     * account correlates by 'id', also auto=true.)
     */
    private static function guardNameOnly(array $corr, string $action): ?array
    {
        if (empty($corr['auto'])) {
            return self::result(false, 'That account matched by name only — confirm & link it first before you can ' . $action . '.', $action, $corr['googleId'] ?? null);
        }
        return null;
    }

    /**
     * The Google account key for a write: the crosswalk id (preferred) or the
     * correlated primaryEmail. Null when nothing is linked/found.
     */
    private static function accountKey(array $corr): ?string
    {
        if (empty($corr['found'])) {
            return null;
        }
        $id = trim((string) ($corr['googleId'] ?? ''));
        if ($id !== '') {
            return $id;
        }
        $email = trim((string) ($corr['primaryEmail'] ?? ''));
        return $email !== '' ? $email : null;
    }

    /** Resolve the Google OU path for a person from their primary school. */
    private function orgUnitFor(array $person): ?string
    {
        $schoolId = $person['primary_school_id'] ?? null;
        if ($schoolId === null || $schoolId === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT google_ou FROM school WHERE school_id = :id');
        $stmt->execute([':id' => (int) $schoolId]);
        $ou = $stmt->fetchColumn();
        return ($ou === false || trim((string) $ou) === '') ? null : (string) $ou;
    }

    /** @return array<string,mixed>|null */
    private function loadPerson(int $personId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT person_id, person_uuid, first_name, last_name, email, upn, employee_id,
                    status, person_type, primary_school_id
             FROM person WHERE person_id = :id'
        );
        $stmt->execute([':id' => $personId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadSourceIds(int $personId): array
    {
        $stmt = $this->db->prepare('SELECT system, source_key, is_active FROM person_source_id WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        return $stmt->fetchAll();
    }

    /** @return array{ok:bool, message:string, action:string, googleId:?string} */
    private static function result(bool $ok, string $message, string $action, ?string $googleId = null): array
    {
        return ['ok' => $ok, 'message' => $message, 'action' => $action, 'googleId' => $googleId];
    }
}
