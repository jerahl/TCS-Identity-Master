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
     *              bucket, action (null when nothing to do), detail (what a write
     *              would change: name/OU deltas, destination OU), message (error
     *              text when bucket='error'). Emitting per person, not just per
     *              action, keeps the (slow, one-remote-lookup-per-person) scan live.
     *   - 'result' once per applied action on a real run — adds ok, message
     *              (and carries the plan item's action/email/detail)
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
                    'bucket' => $decision['bucket'], 'action' => $decision['action'],
                    'detail' => $decision['detail'], 'message' => '']);
            }
            if ($decision['action'] !== null) {
                if ($decision['action'] === 'suspend') {
                    $suspendPlanned++;
                }
                $plan[] = ['person_id' => $pid, 'action' => $decision['action'],
                    'email' => $decision['email'], 'detail' => $decision['detail']];
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
     * `detail` describes exactly what a write would change (name and/or OU deltas,
     * the destination OU) so --verbose can show what's being pushed, not just that
     * something is.
     *
     * @param array<string,mixed> $person
     * @param array<string,mixed> $corr
     * @return array{action:?string, bucket:string, email:string, detail:string}
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
                    return ['action' => null, 'bucket' => 'no_email', 'email' => '', 'detail' => ''];
                }
                $ou = $this->provisioner->activeOrgUnitFor($person);
                $detail = 'new account' . ($ou !== null ? ' in ' . GoogleWorkspaceService::normalizeOu($ou) : '');
                return ['action' => 'create', 'bucket' => 'created', 'email' => (string) $person['email'], 'detail' => $detail];
            }
            // Linked. A golden-active account that Google shows suspended is a
            // manual/out-of-band override — the batch never auto-restores.
            if ($corr['suspended'] === true) {
                return ['action' => null, 'bucket' => 'manual_override', 'email' => $email, 'detail' => ''];
            }
            // Push on name drift OR OU drift — a push writes name + the building's
            // OU, so it also relocates a user whose OU no longer matches their school.
            $parts = [];
            $nameDetail = $this->nameDetail($person, $attrs);
            if ($nameDetail !== '') {
                $parts[] = $nameDetail;
            }
            $desiredOu = $this->provisioner->activeOrgUnitFor($person);
            if ($desiredOu !== null && !GoogleProvisioner::ouEquals($currentOu, $desiredOu)) {
                $parts[] = $this->ouDetail($currentOu, $desiredOu);
            }
            if ($parts !== []) {
                return ['action' => 'push', 'bucket' => 'pushed', 'email' => $email, 'detail' => implode('; ', $parts)];
            }
            return ['action' => null, 'bucket' => 'in_sync', 'email' => $email, 'detail' => ''];
        }

        // disabled / terminated
        if (!$found) {
            return ['action' => null, 'bucket' => 'no_account', 'email' => '', 'detail' => ''];
        }
        $disabledOu = $this->provisioner->disabledOu();
        // Not suspended yet → suspend (which also moves to the disabled OU). The
        // action name already says "suspend"; detail carries only the OU move.
        if ($corr['suspended'] !== true) {
            $detail = ($disabledOu !== '' && !GoogleProvisioner::ouEquals($currentOu, $disabledOu))
                ? $this->ouDetail($currentOu, $disabledOu)
                : '';
            return ['action' => 'suspend', 'bucket' => 'suspended', 'email' => $email, 'detail' => $detail];
        }
        // Already suspended but not in the disabled OU → relocate it there.
        if ($disabledOu !== '' && !GoogleProvisioner::ouEquals($currentOu, $disabledOu)) {
            return ['action' => 'move_disabled', 'bucket' => 'moved', 'email' => $email, 'detail' => $this->ouDetail($currentOu, $disabledOu)];
        }
        return ['action' => null, 'bucket' => 'in_sync', 'email' => $email, 'detail' => ''];
    }

    /** A "name Old Name→New Name" delta, or '' when the name matches. */
    private function nameDetail(array $person, array $attrs): string
    {
        $curGiven  = trim((string) ($attrs['givenname'] ?? ''));
        $curFamily = trim((string) ($attrs['familyname'] ?? ''));
        $wantGiven  = trim((string) ($person['first_name'] ?? ''));
        $wantFamily = trim((string) ($person['last_name'] ?? ''));
        if ($curGiven === $wantGiven && $curFamily === $wantFamily) {
            return '';
        }
        $cur  = trim($curGiven . ' ' . $curFamily);
        $want = trim($wantGiven . ' ' . $wantFamily);
        return 'name ' . ($cur === '' ? '(none)' : $cur) . '→' . $want;
    }

    /** An "OU /old→/new" delta between two OU paths (normalized for display). */
    private function ouDetail(string $current, string $desired): string
    {
        $cur = trim($current) === '' ? '(unknown)' : GoogleWorkspaceService::normalizeOu($current);
        return 'OU ' . $cur . '→' . GoogleWorkspaceService::normalizeOu($desired);
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
