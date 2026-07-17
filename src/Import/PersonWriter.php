<?php

declare(strict_types=1);

namespace App\Import;

use App\Service\AuditService;
use App\Support\Crypto;
use App\Support\Uuid;
use PDO;

/**
 * Applies a match decision to the database: create/update the golden record,
 * maintain the source-ID crosswalk and assignments, or queue a match candidate.
 * Every mutation is audited (audit_log) and, where it affects a person's story,
 * recorded as a lifecycle_event. Importers never touch username/email/locked —
 * those belong to OneSync.
 */
final class PersonWriter
{
    public function __construct(
        private readonly PDO $db,
        private readonly AuditService $audit,
    ) {
    }

    /** Create a new pending person from an incoming row. Returns person_id. */
    public function createPerson(NormalizedRow $row, string $actor): int
    {
        $uuid = Uuid::v4();
        $stmt = $this->db->prepare(
            'INSERT INTO person
               (person_uuid, person_type, status, first_name, middle_name, last_name, preferred_name,
                dob, gender, ethnicity_source, ethnicity_code, alsde_id, employee_id, primary_school_id,
                hire_date, position_start_date, end_date,
                hr_email, position_number, cctr_description,
                phone, address1, address2, city, state_code, zip_code,
                source_of_record, created_by, updated_by)
             VALUES
               (:uuid, :type, :status, :first, :middle, :last, :preferred,
                :dob, :gender, :eth_src, :eth_code, :alsde, :emp, :school_id,
                :hire, :pos_start, :end,
                :hr_email, :pos_num, :cctr,
                :phone, :addr1, :addr2, :city, :state, :zip,
                :sor, :created_by, :updated_by)'
        );
        $stmt->execute([
            ':uuid' => $uuid,
            ':type' => $row->personType ?? 'staff',
            ':status' => 'pending',
            ':first' => $row->firstName,
            ':middle' => $row->middleName,
            ':last' => $row->lastName,
            ':preferred' => $row->preferredName,
            ':dob' => $row->dob,
            ':gender' => $row->gender,
            ':eth_src' => $row->ethnicitySource,
            ':eth_code' => $row->ethnicityCode,
            ':alsde' => $row->alsdeId,
            ':emp' => $row->employeeId,
            ':school_id' => $row->schoolId,
            ':hire' => $row->hireDate,
            ':pos_start' => $row->positionStartDate,
            ':end' => $row->endDate,
            ':hr_email' => $row->hrEmail,
            ':pos_num' => $row->positionNumber,
            ':cctr' => $row->cctrDescription,
            ':phone' => $row->phone,
            ':addr1' => $row->address1,
            ':addr2' => $row->address2,
            ':city' => $row->city,
            ':state' => $row->stateCode,
            ':zip' => $row->zipCode,
            ':sor' => self::sourceOfRecord($row->system),
            ':created_by' => $actor,
            ':updated_by' => $actor,
        ]);
        $personId = (int) $this->db->lastInsertId();

        $this->audit->log('person', $personId, 'insert', null, $this->snapshot($personId), $actor);
        $this->audit->lifecycle($personId, 'create', ['summary' => "Created from {$row->system} feed (source {$row->sourceKey})"], $actor);

        return $personId;
    }

    /**
     * Ensure the (system, source_key) crosswalk points at this person and is
     * active. Portable upsert (select → insert or update) so it runs on both the
     * app's MySQL and the sqlite used by the test suite; a brand-new link is
     * audited, an existing one is refreshed quietly.
     */
    public function attachSourceId(int $personId, string $system, string $sourceKey, string $actor): void
    {
        $before = $this->findSourceId($system, $sourceKey);
        if ($before === null) {
            $this->db->prepare(
                'INSERT INTO person_source_id (person_id, system, source_key, is_active, last_seen)
                 VALUES (:pid, :system, :key, 1, CURRENT_TIMESTAMP)'
            )->execute([':pid' => $personId, ':system' => $system, ':key' => $sourceKey]);
            $this->audit->log('source_id', (int) $this->db->lastInsertId(), 'insert', null,
                ['person_id' => $personId, 'system' => $system, 'source_key' => $sourceKey], $actor);
            return;
        }
        $this->db->prepare(
            'UPDATE person_source_id SET person_id = :pid, is_active = 1, last_seen = CURRENT_TIMESTAMP
             WHERE system = :system AND source_key = :key'
        )->execute([':pid' => $personId, ':system' => $system, ':key' => $sourceKey]);
    }

    /**
     * Drop-out tracking for a full feed. Flags the `$system` crosswalk IDs that
     * are currently active but were NOT present in this run (`$seenKeys`) as
     * inactive — the person is no longer in that source. Mirrors the student
     * drop-out logic (StudentImporter), for staff.
     *
     * It does NOT change person.status: leaving the feed is not, by itself, a
     * disable — that stays a human decision. Deactivating the crosswalk id is what
     * makes the person show up in the dashboard "Not in NextGen — past exit date"
     * review panel (ReviewService::disableCandidates). Each change is audited
     * and put on the person's timeline.
     *
     * Safety valve: a truncated/partial feed would otherwise mark real employees
     * as departed. When the active population is at least `$guardMinActive` and the
     * share that would be deactivated exceeds `$maxRatio`, the step is BLOCKED
     * (nothing is written) so an operator can investigate. Set `$apply` false to
     * compute the counts without writing (dry-run).
     *
     * @param array<string,bool> $seenKeys source keys present in this run (as keys)
     * @return array{active:int,candidates:int,deactivated:int,blocked:bool}
     */
    public function deactivateMissingSourceIds(
        string $system,
        array $seenKeys,
        string $actor,
        bool $apply = true,
        float $maxRatio = 0.2,
        int $guardMinActive = 20
    ): array {
        $rows = $this->db->prepare(
            'SELECT id, person_id, source_key FROM person_source_id WHERE system = :s AND is_active = 1'
        );
        $rows->execute([':s' => $system]);
        $all = $rows->fetchAll();

        $stale = [];
        foreach ($all as $r) {
            if (!isset($seenKeys[(string) $r['source_key']])) {
                $stale[] = $r;
            }
        }

        $active = count($all);
        $candidates = count($stale);
        $result = ['active' => $active, 'candidates' => $candidates, 'deactivated' => 0, 'blocked' => false];

        if ($candidates === 0) {
            return $result;
        }
        // Guard against a partial feed nuking everyone.
        if ($active >= $guardMinActive && ($candidates / $active) > $maxRatio) {
            $result['blocked'] = true;
            return $result;
        }
        if (!$apply) {
            return $result;
        }

        $update = $this->db->prepare('UPDATE person_source_id SET is_active = 0 WHERE id = :id');
        foreach ($stale as $r) {
            $update->execute([':id' => (int) $r['id']]);
            $this->audit->log('source_id', (int) $r['id'], 'update',
                ['is_active' => 1],
                ['is_active' => 0, 'source_key' => $r['source_key'], 'reason' => "absent from {$system} feed"],
                $actor);
            $this->audit->lifecycle((int) $r['person_id'], 'update',
                ['summary' => "Dropped from the {$system} feed — {$system} crosswalk id deactivated (review to disable)."],
                $actor);
        }
        $result['deactivated'] = $candidates;
        return $result;
    }

    /**
     * Adopt a matched AD account's identity as the golden record (live
     * verification): record the objectGUID in the crosswalk and write the AD
     * username (set + LOCKED), email and UPN. Each field is written when AD
     * carries a value that DIFFERS from the golden record (case-insensitively) —
     * so a blank golden value is filled AND a differing one is overwritten to
     * match AD. Setting the username activates a pending person (a no-op for an
     * already-active one). Idempotent: a record that already matches AD yields no
     * writes. Unique clashes (username/email already used by another record)
     * leave the golden record untouched; the GUID link still stands. Returns
     * change notes.
     *
     * @param array{guid?:?string, username?:?string, email?:?string, upn?:?string} $ad
     * @return list<string>
     */
    public function linkAdAccount(int $personId, array $ad, string $actor): array
    {
        $stmt = $this->db->prepare('SELECT username, email, upn, status FROM person WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        $p = $stmt->fetch();
        if ($p === false) {
            return [];
        }

        $username = trim((string) ($ad['username'] ?? ''));
        $email    = trim((string) ($ad['email'] ?? ''));
        $upn      = trim((string) ($ad['upn'] ?? ''));
        $guid     = trim((string) ($ad['guid'] ?? ''));
        $notes = [];

        // The stable link first (idempotent; audited only when new).
        if ($guid !== '') {
            $this->attachSourceId($personId, 'ad', $guid, $actor);
        }

        $sets = [];
        $params = [':id' => $personId];
        $before = [];
        $after = [];
        $setUsername = false;

        if ($username !== '' && !self::sameCi($username, (string) $p['username'])) {
            $sets[] = 'username = :u';
            $sets[] = 'username_assigned_at = CURRENT_TIMESTAMP';
            $sets[] = 'username_locked = 1';
            $params[':u'] = $username;
            $before['username'] = $p['username'];
            $after['username'] = $username;
            $notes[] = trim((string) $p['username']) === ''
                ? "username set to {$username} (locked)"
                : "username changed to {$username} (locked)";
            $setUsername = true;
        }
        if ($email !== '' && !self::sameCi($email, (string) $p['email'])) {
            $sets[] = 'email = :e';
            $params[':e'] = $email;
            $before['email'] = $p['email'];
            $after['email'] = $email;
            $notes[] = trim((string) $p['email']) === '' ? "email set to {$email}" : "email changed to {$email}";
        }
        if ($upn !== '' && !self::sameCi($upn, (string) $p['upn'])) {
            $sets[] = 'upn = :pn';
            $params[':pn'] = $upn;
            $before['upn'] = $p['upn'];
            $after['upn'] = $upn;
            $notes[] = trim((string) $p['upn']) === '' ? "UPN set to {$upn}" : "UPN changed to {$upn}";
        }

        if ($sets !== []) {
            try {
                $this->db->prepare('UPDATE person SET ' . implode(', ', $sets) . ' WHERE person_id = :id')->execute($params);
                $this->audit->log('person', $personId, 'update', $before, $after, $actor);
            } catch (\PDOException $e) {
                if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
                    // username/email already used by another record — leave the
                    // golden record as-is; the GUID link above still stands.
                    return ['AD username/email already in use by another record — golden record left unchanged'];
                }
                throw $e;
            }
        }

        if ($setUsername && ($p['status'] ?? '') === 'pending') {
            $this->db->prepare("UPDATE person SET status = 'active' WHERE person_id = :id AND status = 'pending'")
                ->execute([':id' => $personId]);
            $this->audit->log('person', $personId, 'update', ['status' => 'pending'], ['status' => 'active'], $actor);
            $notes[] = 'activated';
        }

        if ($notes !== []) {
            $this->audit->lifecycle($personId, 'username_assigned',
                ['summary' => 'AD account linked via live verification: ' . implode('; ', $notes) . '.'], $actor);
        }

        return $notes;
    }

    /**
     * Store a newly-set initial password (encrypted) on the golden record, the same
     * shape the OneSync write-back importer uses: only the libsodium ciphertext
     * reaches the DB, and neither the audit row nor any log carries the plaintext.
     * Used when IDM sets the AD password itself on create (Business Rules don't
     * fire on REST events). Re-sending replaces the stored value.
     *
     * @return bool true when a value was stored; false when the person is missing
     *              or the password is blank
     */
    public function recordInitialPassword(int $personId, string $password, string $actor): bool
    {
        if (trim($password) === '') {
            return false;
        }
        $stmt = $this->db->prepare('SELECT initial_password_set_at FROM person WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return false;
        }
        $replaced = ($row['initial_password_set_at'] ?? null) !== null;

        $upd = $this->db->prepare(
            'UPDATE person SET initial_password_enc = :enc, initial_password_set_at = CURRENT_TIMESTAMP
             WHERE person_id = :id'
        );
        $upd->bindValue(':enc', Crypto::encrypt($password), PDO::PARAM_LOB);
        $upd->bindValue(':id', $personId, PDO::PARAM_INT);
        $upd->execute();

        // Audit the fact, never the value.
        $this->audit->log('person', $personId, 'update',
            ['initial_password' => $replaced ? '[set]' : null],
            ['initial_password' => '[set]'], $actor);
        $this->audit->lifecycle($personId, 'password_received',
            ['summary' => $replaced
                ? 'Initial password reset by TCS-IDM (Adaxes).'
                : 'Initial password set by TCS-IDM (Adaxes).'], $actor);
        return true;
    }

    /**
     * Apply a rename cutover to the golden record: set the new username/email/upn
     * (the account stays LOCKED — this is a sanctioned change, not an unlock).
     * Audited + a lifecycle event. Returns whether anything changed.
     */
    public function applyRename(int $personId, string $newUsername, string $newEmail, string $newUpn, string $actor): bool
    {
        $stmt = $this->db->prepare('SELECT username, email, upn FROM person WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        $before = $stmt->fetch();
        if ($before === false) {
            return false;
        }
        $this->db->prepare(
            'UPDATE person
                SET username = :u, email = :e, upn = :pn,
                    username_assigned_at = CURRENT_TIMESTAMP, username_locked = 1, updated_by = :actor
              WHERE person_id = :id'
        )->execute([':u' => $newUsername, ':e' => $newEmail, ':pn' => $newUpn, ':actor' => $actor, ':id' => $personId]);

        $this->audit->log('person', $personId, 'update',
            ['username' => $before['username'], 'email' => $before['email'], 'upn' => $before['upn']],
            ['username' => $newUsername, 'email' => $newEmail, 'upn' => $newUpn], $actor);
        $this->audit->lifecycle($personId, 'username_assigned',
            ['summary' => "Rename applied: {$before['username']} → {$newUsername} (email {$newEmail})."], $actor);
        return true;
    }

    /**
     * Unlink a person's assigned identity — for when the wrong name/employee id
     * caused a bad username to be minted/linked. Clears username/email/upn and the
     * lock (so the minter can re-assign a correct one), and REMOVES the person's
     * `ad` crosswalk row(s) entirely (objectGUID link) so a wrong/stale GUID can
     * neither resolve here nor block re-linking that GUID elsewhere. Does NOT touch
     * the live AD account itself (IT deletes/renames that, or the reconciler creates
     * a fresh corrected account). Audited + a lifecycle event. Returns the change
     * notes (empty if there was nothing linked).
     *
     * @return list<string>
     */
    public function unlinkUsername(int $personId, string $actor, ?string $reason = null): array
    {
        $stmt = $this->db->prepare('SELECT username, email, upn, username_locked FROM person WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        $p = $stmt->fetch();
        if ($p === false) {
            return [];
        }

        $notes = [];
        $hadIdentity = trim((string) $p['username']) !== '' || trim((string) $p['email']) !== '' || trim((string) $p['upn']) !== '';
        if ($hadIdentity) {
            $this->db->prepare(
                'UPDATE person
                    SET username = NULL, email = NULL, upn = NULL,
                        username_assigned_at = NULL, username_locked = 0, updated_by = :actor
                  WHERE person_id = :id'
            )->execute([':actor' => $actor, ':id' => $personId]);
            $this->audit->log('person', $personId, 'update',
                ['username' => $p['username'], 'email' => $p['email'], 'upn' => $p['upn'], 'username_locked' => $p['username_locked']],
                ['username' => null, 'email' => null, 'upn' => null, 'username_locked' => 0], $actor);
            $notes[] = 'cleared username/email/UPN and unlocked';
        }

        // Remove the AD crosswalk entirely (active OR inactive) so the wrong/stale
        // objectGUID can't resolve here or block re-linking it to the right person.
        // The app DB role may lack DELETE (least privilege); if so, fall back to
        // deactivating the row — an inactive AD link no longer resolves a GUID
        // (see AdaxesService::adObjectGuid), so verify still stops matching it.
        $adRows = $this->db->prepare("SELECT id, source_key, is_active FROM person_source_id WHERE person_id = :id AND system = 'ad'");
        $adRows->execute([':id' => $personId]);
        $removed = 0;
        $deactivated = 0;
        foreach ($adRows->fetchAll() as $r) {
            $rid = (int) $r['id'];
            try {
                $this->db->prepare('DELETE FROM person_source_id WHERE id = :rid')->execute([':rid' => $rid]);
                $this->audit->log('source_id', $rid, 'delete',
                    ['system' => 'ad', 'source_key' => $r['source_key'], 'is_active' => (int) $r['is_active']],
                    ['reason' => 'username unlinked'], $actor);
                $removed++;
            } catch (\PDOException $e) {
                // No DELETE privilege → soft-unlink so the operation still succeeds.
                $this->db->prepare('UPDATE person_source_id SET is_active = 0 WHERE id = :rid')->execute([':rid' => $rid]);
                $this->audit->log('source_id', $rid, 'update',
                    ['is_active' => (int) $r['is_active']],
                    ['is_active' => 0, 'source_key' => $r['source_key'], 'reason' => 'username unlinked (no DELETE grant — deactivated)'], $actor);
                $deactivated++;
            }
        }
        if ($removed > 0) {
            $notes[] = "removed {$removed} AD crosswalk link(s)";
        }
        if ($deactivated > 0) {
            $notes[] = "deactivated {$deactivated} AD crosswalk link(s) (grant DELETE on person_source_id to remove fully)";
        }

        if ($notes !== []) {
            $summary = 'Username unlinked' . ($reason !== null && trim($reason) !== '' ? ' (' . trim($reason) . ')' : '') . ': ' . implode('; ', $notes) . '.';
            $this->audit->lifecycle($personId, 'update', ['summary' => $summary], $actor);
        }
        return $notes;
    }

    /** Case-insensitive equality of two trimmed values (matches the AD comparison). */
    private static function sameCi(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    // ---- Manual field overrides --------------------------------------------
    //
    // When an operator hand-edits a golden field, it is pinned in
    // person_field_override so feed imports leave it alone (updateHrFields /
    // upsertAssignment skip pinned fields). Only feed-owned fields are pinnable —
    // pinning a field the feeds don't touch (status, notes, …) would be a no-op.

    /**
     * The golden/assignment fields the feeds own, and therefore the only fields a
     * manual edit can pin. Matches updateHrFields()'s HR candidates plus the
     * assignment 'title'. Anything not listed here is never recorded as an
     * override (the importer wouldn't overwrite it anyway).
     */
    private const PINNABLE_FIELDS = [
        'first_name', 'last_name', 'middle_name', 'preferred_name', 'dob', 'gender',
        'ethnicity_source', 'ethnicity_code', 'alsde_id', 'employee_id', 'primary_school_id',
        'hire_date', 'position_start_date', 'end_date', 'hr_email', 'position_number',
        'cctr_description', 'phone', 'address1', 'address2', 'city', 'state_code', 'zip_code',
        'person_type', 'title',
    ];

    /**
     * The set of fields pinned as manually overridden for a person (golden column
     * names, plus 'title' for the assignment title). Empty when none.
     *
     * @return list<string>
     */
    public function fieldOverrides(int $personId): array
    {
        $stmt = $this->db->prepare('SELECT field FROM person_field_override WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        return array_map(static fn($r) => (string) $r['field'], $stmt->fetchAll());
    }

    /**
     * Pin a field as manually overridden (idempotent — re-pinning refreshes the
     * actor/note). No-op for a field the feeds don't own (see PINNABLE_FIELDS).
     * Portable upsert (delete-then-insert) so it behaves the same on MariaDB and
     * the sqlite test DB.
     */
    public function recordFieldOverride(int $personId, string $field, string $actor, ?string $note = null): void
    {
        if (!in_array($field, self::PINNABLE_FIELDS, true)) {
            return;
        }
        $this->db->prepare('DELETE FROM person_field_override WHERE person_id = :id AND field = :f')
            ->execute([':id' => $personId, ':f' => $field]);
        $this->db->prepare(
            'INSERT INTO person_field_override (person_id, field, actor, note) VALUES (:id, :f, :actor, :note)'
        )->execute([':id' => $personId, ':f' => $field, ':actor' => $actor, ':note' => $note]);
    }

    /**
     * Remove a field's manual-override pin so imports resume syncing it. Audited +
     * a lifecycle note. Returns whether a pin was actually removed.
     */
    public function clearFieldOverride(int $personId, string $field, string $actor): bool
    {
        $stmt = $this->db->prepare('DELETE FROM person_field_override WHERE person_id = :id AND field = :f');
        $stmt->execute([':id' => $personId, ':f' => $field]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->audit->log('person', $personId, 'update', ['override' => $field], ['override' => null], $actor);
        $this->audit->lifecycle($personId, 'update',
            ['summary' => "Cleared manual override on {$field} — imports will sync it again."], $actor);
        return true;
    }

    /** Pin every field in $fields that the feeds own (ignores the rest). */
    private function recordFieldOverrides(int $personId, array $fields, string $actor, ?string $note = null): void
    {
        foreach ($fields as $field) {
            $this->recordFieldOverride($personId, (string) $field, $actor, $note);
        }
    }

    /**
     * Update HR-owned fields on an existing person from an incoming row. Only
     * fields the feed actually provides are written (never blank out existing
     * data); username/email/status are left untouched. Returns true if changed.
     */
    public function updateHrFields(int $personId, NormalizedRow $row, string $actor): bool
    {
        $before = $this->snapshot($personId);
        if ($before === null) {
            return false;
        }

        $changes = self::diffHrFields($before, $row, $this->hrOverridesFor($personId, $row));
        if ($changes === []) {
            return false;
        }

        $set = [];
        $params = [];
        foreach ($changes as $c) {
            $set[] = "{$c['field']} = :{$c['field']}";
            $params[":{$c['field']}"] = $c['to'];
        }

        $params[':id'] = $personId;
        $params[':actor'] = $actor;
        $sql = 'UPDATE person SET ' . implode(', ', $set) . ', updated_by = :actor WHERE person_id = :id';
        $this->db->prepare($sql)->execute($params);

        $after = $this->snapshot($personId);
        $this->audit->log('person', $personId, 'update', $before, $after, $actor);
        $this->audit->lifecycle($personId, 'update', ['summary' => 'Demographics updated from ' . $row->system . ' feed'], $actor);
        return true;
    }

    /** HR-owned columns updateHrFields() may write; null-required fields are always considered. */
    private const HR_REQUIRED = ['first_name', 'last_name'];

    /** Incoming HR values keyed by golden-record column (matches updateHrFields). */
    private static function hrCandidates(NormalizedRow $row): array
    {
        return [
            'first_name' => $row->firstName,
            'last_name' => $row->lastName,
            'middle_name' => $row->middleName,
            'preferred_name' => $row->preferredName,
            'dob' => $row->dob,
            'gender' => $row->gender,
            'ethnicity_source' => $row->ethnicitySource,
            'ethnicity_code' => $row->ethnicityCode,
            'alsde_id' => $row->alsdeId,
            'employee_id' => $row->employeeId,
            'primary_school_id' => $row->schoolId,
            'hire_date' => $row->hireDate,
            'position_start_date' => $row->positionStartDate,
            'end_date' => $row->endDate,
            'hr_email' => $row->hrEmail,
            'position_number' => $row->positionNumber,
            'cctr_description' => $row->cctrDescription,
            'phone' => $row->phone,
            'address1' => $row->address1,
            'address2' => $row->address2,
            'city' => $row->city,
            'state_code' => $row->stateCode,
            'zip_code' => $row->zipCode,
            'person_type' => $row->personType,
        ];
    }

    /**
     * Pure diff: the HR fields that would change moving from a person snapshot
     * `$before` to the incoming `$row`, applying the same "never blank out an
     * existing value, only write when different" rules as updateHrFields(). Shared
     * by the real write and the dry-run preview so they can never disagree.
     *
     * Fields listed in $overridden are pinned (manually edited) and skipped, so a
     * feed value never reverts a hand-edit.
     *
     * @param array<string,mixed> $before a person snapshot (snapshot())
     * @param list<string> $overridden golden columns pinned as manually overridden
     * @return list<array{field:string,from:?string,to:?string}>
     */
    public static function diffHrFields(array $before, NormalizedRow $row, array $overridden = []): array
    {
        $changes = [];
        foreach (self::hrCandidates($row) as $col => $val) {
            if (in_array($col, $overridden, true)) {
                continue; // manually overridden — imports leave it alone
            }
            if ($val === null && !in_array($col, self::HR_REQUIRED, true)) {
                continue; // feed didn't provide it — leave as-is
            }
            if ((string) ($before[$col] ?? '') === (string) ($val ?? '')) {
                continue; // unchanged
            }
            $changes[] = [
                'field' => $col,
                'from'  => isset($before[$col]) ? (string) $before[$col] : null,
                'to'    => $val,
            ];
        }
        return $changes;
    }

    /**
     * Read-only: the HR-field changes updateHrFields() would apply to $personId
     * from $row, without writing. Empty when the person is unknown or unchanged.
     *
     * @return list<array{field:string,from:?string,to:?string}>
     */
    public function previewHrChanges(int $personId, NormalizedRow $row): array
    {
        $before = $this->snapshot($personId);
        return $before === null ? [] : self::diffHrFields($before, $row, $this->hrOverridesFor($personId, $row));
    }

    /**
     * The effective override list for an incoming row: the operator-pinned
     * fields, plus primary_school_id when this row's source must yield placement
     * to NextGen (see placementYieldsToNextgen) — so the person-level school
     * write obeys the same precedence as the assignment primary flag, and
     * previews show exactly what updateHrFields() would do.
     *
     * @return list<string>
     */
    private function hrOverridesFor(int $personId, NormalizedRow $row): array
    {
        $overridden = $this->fieldOverrides($personId);
        if (!in_array('primary_school_id', $overridden, true)
            && $this->placementYieldsToNextgen(self::assignmentSource($row->system), $personId)) {
            $overridden[] = 'primary_school_id';
        }
        return $overridden;
    }

    /** True if a (system, source_key) crosswalk row already exists (so no new link would be made). */
    public function hasSourceId(string $system, string $sourceKey): bool
    {
        return $this->findSourceId($system, $sourceKey) !== null;
    }

    /** Display label for a person: id + name + employee id, or null if unknown. */
    public function personLabel(int $personId): ?array
    {
        $stmt = $this->db->prepare('SELECT first_name, last_name, employee_id FROM person WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }
        return [
            'id'          => $personId,
            'name'        => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']),
            'employee_id' => $r['employee_id'] !== null ? (string) $r['employee_id'] : null,
        ];
    }

    /** A school's name for display, or null if unknown. */
    public function schoolName(int $schoolId): ?string
    {
        $stmt = $this->db->prepare('SELECT name FROM school WHERE school_id = :id');
        $stmt->execute([':id' => $schoolId]);
        $n = $stmt->fetchColumn();
        return $n === false ? null : (string) $n;
    }

    /** Incoming assignment values keyed by column (matches upsertAssignment). */
    private static function assignmentValues(NormalizedRow $row): array
    {
        return [
            'title'          => $row->title,
            'job_code'       => $row->jobCode,
            'fte'            => $row->fte,
            'is_primary'     => $row->isPrimary ? '1' : '0',
            'effective_date' => $row->hireDate,
            'end_date'       => $row->endDate,
        ];
    }

    /**
     * Pure diff of an existing assignment row (or null for a create) vs the
     * incoming row. On create, returns every field; on update, only the columns
     * that differ. Keys are column names, values the incoming value.
     *
     * Columns in $overridden are pinned (manually edited) and skipped on update so
     * a feed value never reverts a hand-edit; on create nothing is pinned yet.
     *
     * @param array<string,mixed>|null $existing
     * @param list<string> $overridden assignment columns pinned as manually overridden
     * @return array<string,?string>
     */
    public static function diffAssignment(?array $existing, NormalizedRow $row, array $overridden = []): array
    {
        $vals = self::assignmentValues($row);
        if ($existing === null) {
            return $vals;
        }
        $changed = [];
        foreach ($vals as $col => $val) {
            if (in_array($col, $overridden, true)) {
                continue; // manually overridden — imports leave it alone
            }
            if ((string) ($existing[$col] ?? '') !== (string) ($val ?? '')) {
                $changed[$col] = $val;
            }
        }
        return $changed;
    }

    /**
     * Read-only preview of what upsertAssignment() would do for this row: whether
     * it would create, update (and which columns), or leave the assignment
     * unchanged. Null when the row has no resolved school (nothing to write).
     *
     * @return array{action:string,school_id:int,school_name:?string,source:string,title:?string,changes:array<string,?string>}|null
     */
    public function previewAssignment(int $personId, NormalizedRow $row): ?array
    {
        if ($row->schoolId === null) {
            return null;
        }
        $source = self::assignmentSource($row->system);
        $find = $this->db->prepare(
            'SELECT title, job_code, fte, is_primary, effective_date, end_date
               FROM assignment WHERE person_id = :pid AND school_id = :sid AND source = :src LIMIT 1'
        );
        $find->execute([':pid' => $personId, ':sid' => $row->schoolId, ':src' => $source]);
        $existing = $find->fetch();
        $existing = $existing === false ? null : $existing;

        $changed = $existing === null ? [] : self::diffAssignment($existing, $row, $this->fieldOverrides($personId));
        return [
            'action'      => $existing === null ? 'create' : ($changed === [] ? 'unchanged' : 'update'),
            'school_id'   => $row->schoolId,
            'school_name' => $this->schoolName($row->schoolId),
            'source'      => $source,
            'title'       => $row->title,
            'changes'     => $changed,
        ];
    }

    /**
     * Update human-owned profile fields from the dashboard's Edit form
     * (demographics, primary location, status, notes). Never touches
     * username/email/upn/username_locked — those belong to OneSync. Audits
     * before/after and emits a lifecycle event (status-aware). Returns changed.
     *
     * @param array<string,?string> $fields whitelisted; only present keys are written
     */
    public function updateProfile(int $personId, array $fields, string $actor): bool
    {
        $before = $this->profileSnapshot($personId);
        if ($before === null) {
            return false;
        }

        $set = [];
        $params = [];
        foreach (array_keys($before) as $col) {
            if (array_key_exists($col, $fields)) {
                $set[] = "{$col} = :{$col}";
                $params[":{$col}"] = ($fields[$col] === '' ? null : $fields[$col]);
            }
        }
        if ($set === []) {
            return false;
        }
        $params[':id'] = $personId;
        $params[':actor'] = $actor;
        $this->db->prepare('UPDATE person SET ' . implode(', ', $set) . ', updated_by = :actor WHERE person_id = :id')
            ->execute($params);

        $after = $this->profileSnapshot($personId);
        $this->audit->log('person', $personId, 'update', $before, $after, $actor);

        $newStatus = (string) ($fields['status'] ?? $before['status']);
        $event = self::statusEventType((string) $before['status'], $newStatus);
        $changed = [];
        foreach ((array) $after as $k => $v) {
            if ((string) ($before[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[] = $k;
            }
        }
        $summary = $changed === [] ? 'Record edited' : 'Edited ' . implode(', ', $changed);
        // Pin the feed-owned fields the operator actually changed so a later
        // import can't revert them (non-feed fields like status/notes are ignored
        // by recordFieldOverrides).
        $this->recordFieldOverrides($personId, $changed, $actor, $summary);
        $this->audit->lifecycle($personId, $event, ['summary' => $summary], $actor);

        return true;
    }

    /**
     * Golden-record columns an operator may overwrite via source reconciliation
     * (picking NextGen vs PowerSchool on the person page). Deliberately excludes
     * username/email/upn/status and anything OneSync owns.
     */
    private const GOLDEN_OVERRIDABLE = [
        'first_name', 'last_name', 'employee_id', 'hr_email', 'hire_date', 'end_date',
        'ethnicity_source', 'ethnicity_code', 'gender', 'phone', 'address1', 'city', 'state_code', 'zip_code',
    ];

    /**
     * Overwrite one or more golden-record columns with operator-chosen values from
     * source reconciliation. Whitelisted columns only (throws otherwise); the same
     * "only write when different" rule as the other writers, so a no-op picks make
     * no audit noise. Audited (before/after) + a lifecycle event. Returns whether
     * anything actually changed.
     *
     * @param array<string,?string> $values golden column => chosen value (null clears)
     */
    public function setGoldenFields(int $personId, array $values, string $actor, string $summary): bool
    {
        foreach (array_keys($values) as $col) {
            if (!in_array($col, self::GOLDEN_OVERRIDABLE, true)) {
                throw new \InvalidArgumentException("Column '{$col}' is not an overridable golden field.");
            }
        }

        $before = $this->snapshot($personId);
        if ($before === null) {
            return false;
        }

        $set = [];
        $params = [];
        $changedCols = [];
        foreach ($values as $col => $val) {
            if ((string) ($before[$col] ?? '') === (string) ($val ?? '')) {
                continue; // unchanged
            }
            $set[] = "{$col} = :{$col}";
            $params[":{$col}"] = $val;
            $changedCols[] = $col;
        }
        if ($set === []) {
            return false;
        }

        $params[':id'] = $personId;
        $params[':actor'] = $actor;
        $this->db->prepare('UPDATE person SET ' . implode(', ', $set) . ', updated_by = :actor WHERE person_id = :id')
            ->execute($params);

        // Pin the hand-edited fields so a later import can't revert them.
        $this->recordFieldOverrides($personId, $changedCols, $actor, $summary);

        $after = $this->snapshot($personId);
        $this->audit->log('person', $personId, 'update', $before, $after, $actor);
        $this->audit->lifecycle($personId, 'update', ['summary' => $summary], $actor);
        return true;
    }

    /** Assignment columns source reconciliation may overwrite (title comes from both feeds). */
    private const ASSIGNMENT_OVERRIDABLE = ['title'];

    /**
     * Overwrite a column on one of the person's assignments with an operator-chosen
     * reconciliation value (e.g. adopt PowerSchool's title). Whitelisted columns
     * only (throws otherwise); the assignment must belong to the person. Audited +
     * a lifecycle event. Returns false if the assignment is missing or unchanged.
     */
    public function setAssignmentField(int $personId, int $assignmentId, string $column, ?string $value, string $actor, string $summary): bool
    {
        if (!in_array($column, self::ASSIGNMENT_OVERRIDABLE, true)) {
            throw new \InvalidArgumentException("Column '{$column}' is not an overridable assignment field.");
        }
        // $column is whitelisted above, so interpolating it is safe.
        $stmt = $this->db->prepare("SELECT {$column} AS cur FROM assignment WHERE id = :aid AND person_id = :pid");
        $stmt->execute([':aid' => $assignmentId, ':pid' => $personId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return false; // no such assignment for this person
        }

        $val = ($value === '' ? null : $value);
        if ((string) ($row['cur'] ?? '') === (string) ($val ?? '')) {
            return false; // unchanged
        }

        $this->db->prepare("UPDATE assignment SET {$column} = :v WHERE id = :aid AND person_id = :pid")
            ->execute([':v' => $val, ':aid' => $assignmentId, ':pid' => $personId]);
        // Pin the hand-edited assignment field so a later import can't revert it.
        $this->recordFieldOverride($personId, $column, $actor, $summary);
        $this->audit->log('assignment', $assignmentId, 'update', [$column => $row['cur']], [$column => $val], $actor);
        $this->audit->lifecycle($personId, 'update', ['summary' => $summary], $actor);
        return true;
    }

    /** Lifecycle event type for a status transition. */
    public static function statusEventType(string $old, string $new): string
    {
        if ($old === $new) {
            return 'update';
        }
        return match ($new) {
            'disabled'   => 'disable',
            'terminated' => 'terminate',
            'active'     => 'enable',
            default      => 'update',
        };
    }

    private function profileSnapshot(int $personId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT person_type, status, first_name, middle_name, last_name, preferred_name, dob, gender,
                    ethnicity_source, ethnicity_code, alsde_id, employee_id, primary_school_id,
                    board_approval_date, board_approval_note, notes, raptor_group_override
             FROM person WHERE person_id = :id'
        );
        $stmt->execute([':id' => $personId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Insert or update the person's assignment for this row's school/source, and
     * keep exactly one primary. No-op if the row has no resolved school.
     */
    public function upsertAssignment(int $personId, NormalizedRow $row, string $actor): void
    {
        if ($row->schoolId === null) {
            return;
        }
        $source = self::assignmentSource($row->system);

        // Who may move the primary school: an operator pin freezes placement
        // against every feed, and NextGen (the HR source of record) outranks the
        // other feeds — a powerschool/intern/sub/contractor row claims the
        // primary only when neither applies. This is what keeps a lagging
        // PowerSchool building from flipping primary_school_id back after every
        // import (the feeds run in a fixed order, so last-writer-wins made the
        // final feed of the night the placement authority by accident).
        $overridden = $this->fieldOverrides($personId);
        $primaryPinned = in_array('primary_school_id', $overridden, true);
        $claimsPrimary = $row->isPrimary && !$primaryPinned
            && !$this->placementYieldsToNextgen($source, $personId);

        $find = $this->db->prepare(
            'SELECT id, title, is_primary FROM assignment WHERE person_id = :pid AND school_id = :sid AND source = :src LIMIT 1'
        );
        $find->execute([':pid' => $personId, ':sid' => $row->schoolId, ':src' => $source]);
        $existing = $find->fetch();

        if ($existing !== false) {
            // A manually overridden title is preserved; other columns still sync.
            $title = in_array('title', $overridden, true)
                ? ($existing['title'] ?? null)
                : $row->title;
            // Pinned placement → the operator owns the primary flags; leave the
            // row's flag exactly as it is.
            $pri = $primaryPinned ? (int) $existing['is_primary'] : ($claimsPrimary ? 1 : 0);
            $this->db->prepare(
                'UPDATE assignment SET title = :title, job_code = :jc, fte = :fte, is_primary = :pri,
                        effective_date = :eff, end_date = :end WHERE id = :id'
            )->execute([
                ':title' => $title, ':jc' => $row->jobCode, ':fte' => $row->fte,
                ':pri' => $pri, ':eff' => $row->hireDate, ':end' => $row->endDate,
                ':id' => (int) $existing['id'],
            ]);
            $assignmentId = (int) $existing['id'];
        } else {
            $this->db->prepare(
                'INSERT INTO assignment (person_id, school_id, title, job_code, fte, is_primary, effective_date, end_date, source)
                 VALUES (:pid, :sid, :title, :jc, :fte, :pri, :eff, :end, :src)'
            )->execute([
                ':pid' => $personId, ':sid' => $row->schoolId, ':title' => $row->title, ':jc' => $row->jobCode,
                ':fte' => $row->fte, ':pri' => $claimsPrimary ? 1 : 0, ':eff' => $row->hireDate,
                ':end' => $row->endDate, ':src' => $source,
            ]);
            $assignmentId = (int) $this->db->lastInsertId();
            $this->audit->log('assignment', $assignmentId, 'insert', null,
                ['person_id' => $personId, 'school_id' => $row->schoolId, 'source' => $source], $actor);
        }

        // Maintain a single primary + the person's primary_school_id.
        if ($claimsPrimary) {
            $this->db->prepare('UPDATE assignment SET is_primary = 0 WHERE person_id = :pid AND id <> :id')
                ->execute([':pid' => $personId, ':id' => $assignmentId]);
            $this->db->prepare('UPDATE person SET primary_school_id = :sid WHERE person_id = :pid')
                ->execute([':sid' => $row->schoolId, ':pid' => $personId]);
        }
    }

    /**
     * Whether a row from $source must yield placement (the primary school) to
     * NextGen: true when the source is one of the OTHER feeds and the person has
     * any NextGen assignment. NextGen is the HR source of record for where a
     * person works — the PowerSchool CSV map has documented (and honored) that
     * rule by not importing school at all; this enforces the same precedence on
     * every path that does carry a school. NextGen rows and manual operator
     * writes are never blocked here (the operator's stronger tool is the
     * primary_school_id pin, which freezes placement against every source).
     */
    private function placementYieldsToNextgen(string $source, int $personId): bool
    {
        if ($source === 'nextgen' || $source === 'manual') {
            return false;
        }
        $stmt = $this->db->prepare("SELECT 1 FROM assignment WHERE person_id = :pid AND source = 'nextgen' LIMIT 1");
        $stmt->execute([':pid' => $personId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Queue a candidate for human review (no person change). */
    public function createMatchCandidate(int $stagingId, int $candidatePersonId, float $score, string $basis): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO match_candidate (staging_id, candidate_person_id, score, match_basis, status)
             VALUES (:sid, :pid, :score, :basis, :status)'
        );
        $stmt->execute([
            ':sid' => $stagingId, ':pid' => $candidatePersonId,
            ':score' => $score, ':basis' => $basis, ':status' => 'pending',
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function snapshot(int $personId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT person_type, status, first_name, middle_name, last_name, preferred_name, dob, gender,
                    ethnicity_source, ethnicity_code, alsde_id, employee_id, primary_school_id,
                    hire_date, position_start_date, end_date,
                    hr_email, position_number, cctr_description,
                    phone, address1, address2, city, state_code, zip_code
             FROM person WHERE person_id = :id'
        );
        $stmt->execute([':id' => $personId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function findSourceId(string $system, string $sourceKey): ?array
    {
        $stmt = $this->db->prepare('SELECT id, person_id FROM person_source_id WHERE system = :s AND source_key = :k');
        $stmt->execute([':s' => $system, ':k' => $sourceKey]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private const FEED_SYSTEMS = ['nextgen', 'powerschool', 'intern', 'sub', 'contractor'];

    private static function sourceOfRecord(string $system): string
    {
        return in_array($system, self::FEED_SYSTEMS, true) ? $system : 'manual';
    }

    private static function assignmentSource(string $system): string
    {
        return in_array($system, self::FEED_SYSTEMS, true) ? $system : 'manual';
    }
}
