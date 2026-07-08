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

    public function __construct(
        ?PDO $db = null,
        ?GoogleProvisioner $provisioner = null,
        ?float $maxRatio = null,
        ?int $guardMinLinked = null,
    ) {
        $this->db = $db ?? Db::connect(Db::ROLE_WRITEBACK);
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
     * @return array{dry_run:bool, blocked:bool, configured:bool, counts:array<string,int>, actions:array<int,array<string,mixed>>, note:?string}
     */
    public function run(bool $dryRun = false, ?string $actor = null): array
    {
        $actor ??= 'system:google_sync';
        $counts = [
            'eligible' => 0, 'created' => 0, 'pushed' => 0, 'suspended' => 0,
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

        foreach ($this->eligiblePeople() as $person) {
            $counts['eligible']++;
            $corr = $this->google->correlate($person, $this->sourceIds((int) $person['person_id']));
            if (!$corr['ok']) {
                $counts['errors']++;
                continue;
            }
            if (!empty($corr['found'])) {
                $linked++;
            }
            $decision = $this->decide($person, $corr);
            $counts[$decision['bucket']]++;
            if ($decision['action'] !== null) {
                if ($decision['action'] === 'suspend') {
                    $suspendPlanned++;
                }
                $plan[] = ['person_id' => (int) $person['person_id'], 'action' => $decision['action'], 'email' => $decision['email']];
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
            if ($this->hasNameDrift($person, $corr['attributes'] ?? [])) {
                return ['action' => 'push', 'bucket' => 'pushed', 'email' => $email];
            }
            return ['action' => null, 'bucket' => 'in_sync', 'email' => $email];
        }

        // disabled / terminated
        if (!$found) {
            return ['action' => null, 'bucket' => 'no_account', 'email' => ''];
        }
        if ($corr['suspended'] === true) {
            return ['action' => null, 'bucket' => 'in_sync', 'email' => $email];
        }
        return ['action' => 'suspend', 'bucket' => 'suspended', 'email' => $email];
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
        return $this->db->query(
            "SELECT person_id, person_uuid, first_name, last_name, email, upn, employee_id,
                    status, person_type, primary_school_id
             FROM person
             WHERE status IN ('active','pending','disabled','terminated')
             ORDER BY person_id"
        )->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    private function sourceIds(int $personId): array
    {
        $stmt = $this->db->prepare('SELECT system, source_key, is_active FROM person_source_id WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        return $stmt->fetchAll();
    }
}
