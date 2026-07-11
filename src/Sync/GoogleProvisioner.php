<?php

declare(strict_types=1);

namespace App\Sync;

use App\Config;
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
            'link'         => $this->doLink($person, $corr, $actor, $dryRun),
            'create'       => $this->doCreate($person, $sourceIds, $corr, $ou, $actor, $dryRun),
            'push'         => $this->doPush($person, $corr, $ou, $actor, $dryRun),
            'suspend'      => $this->doSuspend($person, $corr, $actor, $dryRun),
            'move_disabled' => $this->doMoveDisabled($person, $corr, $actor, $dryRun),
            'license'      => $this->doLicense($person, $corr, $actor, $dryRun),
            'unlicense'    => $this->doUnlicense($person, $corr, $actor, $dryRun),
            'restore'      => $this->doRestore($person, $corr, $actor, $dryRun),
            default        => self::result(false, "Unknown Google action '{$action}'.", $action),
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
        // Faculty/staff get the Education Plus license on creation (seat permitting).
        $msg = "Created Google account {$res['primaryEmail']}.";
        if (self::isFacultyStaff($person) && $this->google->licenseEnabled()) {
            $key = $googleId !== '' ? $googleId : (string) $res['primaryEmail'];
            $lic = $this->tryAssignLicense($key, (string) $res['primaryEmail']);
            if ($lic['ok']) {
                $this->audit->lifecycle((int) $person['person_id'], 'update', ['summary' => 'Assigned Google license: ' . (string) $res['primaryEmail']], $actor);
            }
            $msg .= ' ' . ucfirst($lic['note']) . '.';
        }
        return self::result(true, $msg, 'create', $googleId);
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
        $disabledOu = $this->disabledOu();
        $ouOk = $disabledOu === '' || self::ouEquals((string) ($corr['attributes']['orgunitpath'] ?? ''), $disabledOu);
        if (!empty($corr['found']) && $corr['suspended'] === true && $ouOk) {
            return self::result(true, 'Google account is already suspended' . ($disabledOu !== '' ? ' (and in the disabled OU)' : '') . ' — no change.', 'suspend', $corr['googleId'] ?? null);
        }
        if ($dryRun) {
            $moveNote = ($disabledOu !== '' && !$ouOk) ? " and move to {$disabledOu}" : '';
            $verb = ($corr['suspended'] === true) ? "move {$corr['primaryEmail']} to {$disabledOu}" : "suspend {$corr['primaryEmail']}{$moveNote}";
            return self::result(true, "Would {$verb}.", 'suspend', $corr['googleId'] ?? null);
        }

        // Suspend (unless it's already suspended and we're only here to fix the OU).
        $email = (string) ($corr['primaryEmail'] ?? '');
        if ($corr['suspended'] !== true) {
            $res = $this->google->suspendUser($key);
            $this->reflect($person, 'Disable', $res, $actor);
            if (!$res['ok']) {
                return self::result(false, 'Suspend failed: ' . (string) $res['error'], 'suspend');
            }
            $email = (string) ($res['primaryEmail'] ?? $email);
            $this->audit->lifecycle((int) $person['person_id'], 'disable',
                ['summary' => 'Suspended Google Workspace account (direct): ' . $email], $actor);
        }
        // Relocate to the disabled OU. Best-effort: a move failure doesn't undo the
        // suspend — the next run replans a 'move_disabled' and retries.
        $moved = $this->relocateToDisabledOu($person, $key, $disabledOu, $ouOk, $actor);
        $this->ensureCrosswalk($person, $corr, $actor);
        return self::result(true, "Suspended {$email}" . ($moved ? " and moved to {$disabledOu}." : '.'), 'suspend', $corr['googleId'] ?? null);
    }

    /**
     * Move an already-suspended account to the disabled OU (the batch's retry /
     * heal path for a suspended user sitting in the wrong OU). No suspend — the
     * account is already suspended.
     *
     * @param array<string,mixed> $person
     * @param array<string,mixed> $corr
     */
    private function doMoveDisabled(array $person, array $corr, string $actor, bool $dryRun): array
    {
        $key = self::accountKey($corr);
        if ($key === null) {
            return self::result(false, 'No linked Google account to move.', 'move_disabled');
        }
        if (($guard = self::guardNameOnly($corr, 'move')) !== null) {
            return $guard;
        }
        $disabledOu = $this->disabledOu();
        if ($disabledOu === '') {
            return self::result(false, 'No disabled OU configured (GOOGLE_DISABLED_OU).', 'move_disabled', $corr['googleId'] ?? null);
        }
        if (self::ouEquals((string) ($corr['attributes']['orgunitpath'] ?? ''), $disabledOu)) {
            return self::result(true, 'Already in the disabled OU — no change.', 'move_disabled', $corr['googleId'] ?? null);
        }
        if ($dryRun) {
            return self::result(true, "Would move {$corr['primaryEmail']} to {$disabledOu}.", 'move_disabled', $corr['googleId'] ?? null);
        }
        $moved = $this->relocateToDisabledOu($person, $key, $disabledOu, false, $actor);
        if (!$moved) {
            return self::result(false, "Move to {$disabledOu} failed.", 'move_disabled', $corr['googleId'] ?? null);
        }
        $this->ensureCrosswalk($person, $corr, $actor);
        return self::result(true, "Moved {$corr['primaryEmail']} to {$disabledOu}.", 'move_disabled', $corr['googleId'] ?? null);
    }

    /**
     * Move an account to the disabled OU when one is configured and it isn't there
     * already. Returns true when a move was actually applied. Reflects the write
     * and notes it on the timeline; a failure is logged but not thrown so it never
     * undoes a suspend.
     *
     * @param array<string,mixed> $person
     */
    private function relocateToDisabledOu(array $person, string $key, string $disabledOu, bool $ouOk, string $actor): bool
    {
        if ($disabledOu === '' || $ouOk) {
            return false;
        }
        $mv = $this->google->moveUser($key, $disabledOu);
        $this->reflect($person, 'Edit', $mv, $actor);
        if (!$mv['ok']) {
            error_log('[idm] google disabled-OU move failed for person ' . (int) $person['person_id'] . ': ' . (string) $mv['error']);
            return false;
        }
        $this->audit->lifecycle((int) $person['person_id'], 'update',
            ['summary' => 'Moved suspended Google Workspace account to the disabled OU: ' . $disabledOu], $actor);
        return true;
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

    /**
     * Assign the Education Plus (staff) license to an active faculty/staff account
     * (its own sync action, so a user missing only a license is corrected without a
     * full push). Availability is checked first when a seat cap is set.
     *
     * @param array<string,mixed> $person
     * @param array<string,mixed> $corr
     */
    private function doLicense(array $person, array $corr, string $actor, bool $dryRun): array
    {
        if (!$this->google->licenseEnabled()) {
            return self::result(false, 'License management is off (GOOGLE_LICENSE_ENABLED + GOOGLE_LICENSE_SKU).', 'license');
        }
        $key = self::accountKey($corr);
        if ($key === null) {
            return self::result(false, 'No linked Google account to license.', 'license');
        }
        if (($guard = self::guardNameOnly($corr, 'license')) !== null) {
            return $guard;
        }
        $email = (string) ($corr['primaryEmail'] ?? '');
        $usage = $this->google->licenseUsage();
        if (!$this->heldByUsage($usage, $key, $email) && self::noSeat($usage)) {
            return self::result(false, "No license seat available (used {$usage['used']}/{$usage['seats']}).", 'license', $corr['googleId'] ?? null);
        }
        if ($dryRun) {
            return self::result(true, "Would assign license to {$email}.", 'license', $corr['googleId'] ?? null);
        }
        $r = $this->google->assignLicense($key);
        if (!$r['ok']) {
            return self::result(false, 'License assign failed: ' . (string) $r['error'], 'license', $corr['googleId'] ?? null);
        }
        $this->audit->lifecycle((int) $person['person_id'], 'update', ['summary' => 'Assigned Google license: ' . $email], $actor);
        return self::result(true, "Assigned license to {$email}.", 'license', $corr['googleId'] ?? null);
    }

    /**
     * Remove the license from an account (suspended users release their seat).
     *
     * @param array<string,mixed> $person
     * @param array<string,mixed> $corr
     */
    private function doUnlicense(array $person, array $corr, string $actor, bool $dryRun): array
    {
        if (!$this->google->licenseEnabled()) {
            return self::result(false, 'License management is off.', 'unlicense');
        }
        $key = self::accountKey($corr);
        if ($key === null) {
            return self::result(false, 'No linked Google account to unlicense.', 'unlicense');
        }
        $email = (string) ($corr['primaryEmail'] ?? '');
        if ($dryRun) {
            return self::result(true, "Would remove license from {$email}.", 'unlicense', $corr['googleId'] ?? null);
        }
        $r = $this->google->removeLicense($key);
        if (!$r['ok']) {
            return self::result(false, 'License remove failed: ' . (string) $r['error'], 'unlicense', $corr['googleId'] ?? null);
        }
        $this->audit->lifecycle((int) $person['person_id'], 'update', ['summary' => 'Removed Google license: ' . $email], $actor);
        return self::result(true, "Removed license from {$email}.", 'unlicense', $corr['googleId'] ?? null);
    }

    /**
     * Assign the license, checking seat availability first. Returns ok + a short
     * note (for folding into another action's message, e.g. create).
     *
     * @return array{ok:bool, note:string}
     */
    private function tryAssignLicense(string $key, string $email): array
    {
        $usage = $this->google->licenseUsage();
        if (!$this->heldByUsage($usage, $key, $email) && self::noSeat($usage)) {
            return ['ok' => false, 'note' => "no license seat available (used {$usage['used']}/{$usage['seats']})"];
        }
        $r = $this->google->assignLicense($key);
        return $r['ok'] ? ['ok' => true, 'note' => 'licensed'] : ['ok' => false, 'note' => 'license failed: ' . (string) $r['error']];
    }

    /** True when a seat cap is set and full (uncapped/unknown availability = room). */
    private static function noSeat(array $usage): bool
    {
        return ($usage['seats'] ?? 0) > 0 && $usage['available'] !== null && (int) $usage['available'] <= 0;
    }

    /** Whether the account (by id or email) already holds the license, per usage(). */
    private function heldByUsage(array $usage, string $key, string $email): bool
    {
        $set = $usage['users'] ?? null;
        if (!is_array($set)) {
            return false;
        }
        return isset($set[strtolower($key)]) || ($email !== '' && isset($set[strtolower($email)]));
    }

    /** People types that receive the staff license. */
    public static function isFacultyStaff(array $person): bool
    {
        return in_array(strtolower(trim((string) ($person['person_type'] ?? ''))), ['faculty', 'staff'], true);
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

    /**
     * The Google OU path an ACTIVE person belongs in — their primary school's
     * google_ou. Null when the person has no school or the school has no google_ou
     * set (callers then leave the OU alone). Public so the batch can detect OU
     * drift and plan a push.
     */
    public function activeOrgUnitFor(array $person): ?string
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

    /** Backwards-compatible internal alias. */
    private function orgUnitFor(array $person): ?string
    {
        return $this->activeOrgUnitFor($person);
    }

    /**
     * The Google OU suspended accounts are moved to (GOOGLE_DISABLED_OU, default
     * /tcs/faculty/disabled). '' disables the disabled-OU move entirely.
     */
    public function disabledOu(): string
    {
        return trim((string) Config::get('GOOGLE_DISABLED_OU', '/tcs/faculty/disabled'));
    }

    /** OU-path equality, normalized (leading slash) and case-insensitive. */
    public static function ouEquals(string $a, string $b): bool
    {
        return strcasecmp(GoogleWorkspaceService::normalizeOu($a), GoogleWorkspaceService::normalizeOu($b)) === 0;
    }

    /** @return array<string,mixed>|null */
    private function loadPerson(int $personId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT person_id, person_uuid, username, first_name, last_name, email, upn, employee_id,
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
