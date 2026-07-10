<?php

declare(strict_types=1);

namespace App\Sync;

use App\Config;
use App\Db;
use App\Service\GoogleWorkspaceService;
use PDO;

/**
 * Batch reconciliation of the golden record to Google Workspace — the piece that
 * lets IDM REPLACE OneSync's Google destination. Shared by the CLI
 * (bin/sync_google.php / cron) and the "Sync to Google" web action.
 *
 * The golden record is the source of truth that DRIVES Google state:
 *   - active/pending + golden email, no Google account   -> create
 *   - active/pending, linked, name drift                 -> push
 *   - disabled/terminated, linked, not suspended         -> suspend
 * Everything else is left alone. Notably the batch NEVER auto-restores a
 * suspended account (restore is always an explicit human action) — so a
 * per-person manual suspend is not silently undone on the next run.
 *
 * Safety: supports --dry-run (plan only, no writes) and a threshold guardrail —
 * if the share of suspends across the linked population exceeds
 * GOOGLE_SYNC_MAX_RATIO on a sizable population, the whole run is BLOCKED
 * (nothing is written) so a bad feed can't mass-suspend accounts. Mirrors the
 * PersonWriter::deactivateMissingSourceIds guard.
 */
final class GoogleSync
{
    private PDO $db;
    private GoogleProvisioner $provisioner;
    private GoogleWorkspaceService $google;
    private float $maxRatio;
    private int $guardMinLinked;
    /** When non-empty, the run is restricted to these person_ids (test cohort). */
    private array $restrictPersonIds = [];

    public function __construct(
        ?PDO $db = null,
        ?GoogleProvisioner $provisioner = null,
        ?float $maxRatio = null,
        ?int $guardMinLinked = null,
    ) {
        // The app role: this reads the golden record (person, person_source_id)
        // and writes the crosswalk + audit + account_sync_status via
        // GoogleProvisioner — the same role the per-person Google buttons use.
        // The narrow onesync write-back role can't SELECT person_source_id.
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
        $this->provisioner = $provisioner ?? new GoogleProvisioner($this->db);
        $this->google = $this->provisioner->service();
        $this->maxRatio = $maxRatio ?? max(0.0, (float) Config::get('GOOGLE_SYNC_MAX_RATIO', '0.2'));
        $this->guardMinLinked = $guardMinLinked ?? max(1, (int) Config::get('GOOGLE_SYNC_GUARD_MIN', '20'));
    }

    public function configured(): bool
    {
        return $this->google->configured();
    }

    /**
     * Plan and (unless dry-run) apply the reconciliation.
     *
     * $log, when given, is a streaming progress hook for the CLI's --verbose mode
     * (uncapped, unlike the returned `actions` list; never affects the return
     * value). Signature: fn(string $event, array $data), where $event is:
     *   - 'start'  once before the scan — data: total (eligible count)
     *   - 'scan'   once per person as it's correlated — data: person_id, email,
     *              bucket, action (null when nothing to do), message (error text
     *              when bucket='error'). Emitting per person, not just per action,
     *              keeps the (slow, one-remote-lookup-per-person) scan visibly live.
     *   - 'result' once per applied action on a real run — adds ok, message
     *
     * $onlyPersonIds, when non-empty, restricts the whole run to those person_ids —
     * for exercising a few users live without touching everyone (test cohort).
     * NOTE: the mass-suspend guardrail's denominator is the linked accounts *in the
     * cohort*, so it's effectively bypassed on a tiny cohort — deliberate, since the
     * whole point is to act on a handful of accounts.
     *
     * @param callable(string,array<string,mixed>):void|null $log
     * @param list<int> $onlyPersonIds
     * @return array{dry_run:bool, blocked:bool, configured:bool, counts:array<string,int>, actions:array<int,array<string,mixed>>, note:?string}
     */
    public function run(bool $dryRun = false, ?string $actor = null, ?callable $log = null, array $onlyPersonIds = []): array
    {
        $this->restrictPersonIds = array_values(array_unique(array_map('intval', $onlyPersonIds)));
        $actor ??= 'system:google_sync';
        $counts = [
            'eligible' => 0, 'created' => 0, 'pushed' => 0, 'suspended' => 0, 'moved' => 0,
            'in_sync' => 0, 'no_email' => 0, 'no_account' => 0, 'manual_override' => 0, 'errors' => 0,
        ];

        if (!$this->configured()) {
            return ['dry_run' => $dryRun, 'blocked' => false, 'configured' => false, 'counts' => $counts,
                'actions' => [], 'note' => 'Direct Google provisioning is off (GOOGLE_DIRECT_ENABLED + GOOGLE_SA_*).'];
        }

        // ---- Pass 1: plan (correlate + decide, no writes) ----
        $plan = [];           // list of ['person_id','action','email']
        $linked = 0;          // people with a live Google account (denominator for the guard)
        $suspendPlanned = 0;

        $people = $this->eligiblePeople();
        if ($log !== null) {
            $log('start', ['total' => count($people)]);
        }
        foreach ($people as $person) {
            $counts['eligible']++;
            $pid = (int) $person['person_id'];
            $corr = $this->google->correlate($person, $this->sourceIds($pid));
            if (!$corr['ok']) {
                $counts['errors']++;
                if ($log !== null) {
                    $log('scan', ['person_id' => $pid, 'email' => (string) ($person['email'] ?? ''),
                        'bucket' => 'error', 'action' => null, 'message' => (string) ($corr['error'] ?? '')]);
                }
                continue;
            }
            if (!empty($corr['found'])) {
                $linked++;
            }
            $decision = $this->decide($person, $corr);
            $counts[$decision['bucket']]++;
            if ($log !== null) {
                $log('scan', ['person_id' => $pid, 'email' => $decision['email'],
                    'bucket' => $decision['bucket'], 'action' => $decision['action'], 'message' => '']);
            }
            if ($decision['action'] !== null) {
                if ($decision['action'] === 'suspend') {
                    $suspendPlanned++;
                }
                $plan[] = ['person_id' => $pid, 'action' => $decision['action'], 'email' => $decision['email']];
            }
        }

        // ---- Guardrail: block a mass-suspend from a bad feed ----
        $blocked = $linked >= $this->guardMinLinked && $suspendPlanned > 0
            && ($suspendPlanned / $linked) > $this->maxRatio;
        if ($blocked) {
            return ['dry_run' => $dryRun, 'blocked' => true, 'configured' => true, 'counts' => $counts,
                'actions' => array_slice($plan, 0, 50),
                'note' => sprintf('BLOCKED: %d suspends across %d linked accounts (> %.0f%%). Nothing was written — investigate the source data.',
                    $suspendPlanned, $linked, $this->maxRatio * 100)];
        }

        if ($dryRun) {
            return ['dry_run' => true, 'blocked' => false, 'configured' => true, 'counts' => $counts,
                'actions' => array_slice($plan, 0, 50), 'note' => null];
        }

        // ---- Pass 2: apply ----
        foreach ($plan as $item) {
            $res = $this->provisioner->provision($item['person_id'], $item['action'], $actor);
            if (!$res['ok']) {
                $counts['errors']++;
            }
            if ($log !== null) {
                $log('result', $item + ['ok' => (bool) $res['ok'], 'message' => (string) ($res['message'] ?? '')]);
            }
        }

        return ['dry_run' => false, 'blocked' => false, 'configured' => true, 'counts' => $counts,
            'actions' => array_slice($plan, 0, 50), 'note' => null];
    }

    /**
     * Decide the reconciliation action for one person given their correlation.
     *
     * @param array<string,mixed> $person
     * @param array<string,mixed> $corr
     * @return array{action:?string, bucket:string, email:string}
     */
    private function decide(array $person, array $corr): array
    {
        $status = (string) ($person['status'] ?? '');
        $active = in_array($status, ['active', 'pending'], true);
        $found = !empty($corr['found']);
        $email = (string) ($corr['primaryEmail'] ?? ($person['email'] ?? ''));
        $attrs = is_array($corr['attributes'] ?? null) ? $corr['attributes'] : [];
        $currentOu = (string) ($attrs['orgunitpath'] ?? '');

        if ($active) {
            if (!$found) {
                if (trim((string) ($person['email'] ?? '')) === '') {
                    return ['action' => null, 'bucket' => 'no_email', 'email' => ''];
                }
                return ['action' => 'create', 'bucket' => 'created', 'email' => (string) $person['email']];
            }
            // Linked. A golden-active account that Google shows suspended is a
            // manual/out-of-band override — the batch never auto-restores.
            if ($corr['suspended'] === true) {
                return ['action' => null, 'bucket' => 'manual_override', 'email' => $email];
            }
            // Push on name drift OR OU drift — a push writes name + the building's
            // OU, so it also relocates a user whose OU no longer matches their school.
            if ($this->hasNameDrift($person, $attrs) || $this->hasActiveOuDrift($person, $currentOu)) {
                return ['action' => 'push', 'bucket' => 'pushed', 'email' => $email];
            }
            return ['action' => null, 'bucket' => 'in_sync', 'email' => $email];
        }

        // disabled / terminated
        if (!$found) {
            return ['action' => null, 'bucket' => 'no_account', 'email' => ''];
        }
        // Not suspended yet → suspend (which also moves to the disabled OU).
        if ($corr['suspended'] !== true) {
            return ['action' => 'suspend', 'bucket' => 'suspended', 'email' => $email];
        }
        // Already suspended but not in the disabled OU → relocate it there.
        $disabledOu = $this->provisioner->disabledOu();
        if ($disabledOu !== '' && !GoogleProvisioner::ouEquals($currentOu, $disabledOu)) {
            return ['action' => 'move_disabled', 'bucket' => 'moved', 'email' => $email];
        }
        return ['action' => null, 'bucket' => 'in_sync', 'email' => $email];
    }

    /**
     * True when an active person's Google OU differs from their building's
     * (school.google_ou). Only signals drift when a desired OU is resolvable — a
     * person with no school / no google_ou is left where they are.
     *
     * @param array<string,mixed> $person
     */
    private function hasActiveOuDrift(array $person, string $currentOu): bool
    {
        $desired = $this->provisioner->activeOrgUnitFor($person);
        if ($desired === null) {
            return false;
        }
        return !GoogleProvisioner::ouEquals($currentOu, $desired);
    }

    /** True when the golden first/last name differs from what Google holds. */
    private function hasNameDrift(array $person, array $attrs): bool
    {
        $given = trim((string) ($attrs['givenname'] ?? ''));
        $family = trim((string) ($attrs['familyname'] ?? ''));
        return $given !== trim((string) ($person['first_name'] ?? ''))
            || $family !== trim((string) ($person['last_name'] ?? ''));
    }

    /** @return array<int,array<string,mixed>> people to reconcile. */
    private function eligiblePeople(): array
    {
        $sql = "SELECT person_id, person_uuid, username, first_name, last_name, email, upn, employee_id,
                       status, person_type, primary_school_id
                FROM person
                WHERE status IN ('active','pending','disabled','terminated')";
        // Test-cohort restriction: values are ints (cast in run()), so inlining is safe.
        if ($this->restrictPersonIds !== []) {
            $sql .= ' AND person_id IN (' . implode(',', array_map('intval', $this->restrictPersonIds)) . ')';
        }
        $sql .= ' ORDER BY person_id';
        return $this->db->query($sql)->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    private function sourceIds(int $personId): array
    {
        $stmt = $this->db->prepare('SELECT system, source_key, is_active FROM person_source_id WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        return $stmt->fetchAll();
    }
}
