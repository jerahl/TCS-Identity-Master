<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Persists a sync run's per-item progress events as service_run_log rows, so
 * the web console's log view can show WHAT a run touched — not just the
 * rolled-up counts in service_run. The CLIs compose this with their --verbose
 * console logger: the same event stream drives both.
 *
 * The from*() classifiers are pure (event in, entry-or-null out) so the
 * "what's worth persisting, at which severity" rules are unit-testable:
 *   - 'attention'  errors / needs-review / guardrail-blocked / license-blocked
 *                  — exactly what the Outputs "requires attention" tiles count
 *   - 'change'     a change actually applied (created / pushed / expired / …)
 *   - 'info'       context (writes-off previews, capped, manual overrides)
 *   - null         noise at population scale (in-sync, no-op, skip) — the
 *                  summary counts already cover those
 *
 * Writes go through ServiceRunLog::entry(), which no-ops on a null run id
 * (dry runs aren't recorded) and never throws.
 */
final class RunLogRecorder
{
    private ?ServiceRunLog $log;
    private ?int $runId;
    private int $seq = 0;

    public function __construct(?ServiceRunLog $log, ?int $runId)
    {
        $this->log = $log;
        $this->runId = $runId;
    }

    /** Persist one classified entry; null (nothing noteworthy) is ignored. */
    public function add(?array $entry): void
    {
        if ($entry === null || $this->log === null || $this->runId === null) {
            return;
        }
        $this->log->entry($this->runId, ++$this->seq, $entry);
    }

    /** Hook for GoogleSync's progress events (bin/sync_google.php). */
    public function google(string $event, array $d): void
    {
        $this->add(self::fromGoogleEvent($event, $d));
    }

    /** Hook for AdaxesReconciler's progress events (bin/adaxes_sync.php). */
    public function adaxes(string $event, array $d): void
    {
        if ($event === 'item') {
            $this->add(self::fromAdaxesItem($d));
        }
    }

    /**
     * Classify a GoogleSync progress event ('scan' during planning, 'result'
     * per applied action). Planned actions are NOT persisted from 'scan' —
     * each produces a 'result' when applied, and persisting both would show
     * every change twice.
     *
     * @return array{phase:string, person_id:?int, subject:string, outcome:string, level:string, detail:string}|null
     */
    public static function fromGoogleEvent(string $event, array $d): ?array
    {
        if ($event === 'scan') {
            $bucket = (string) ($d['bucket'] ?? '');
            $base = [
                'phase'     => 'scan',
                'person_id' => (int) ($d['person_id'] ?? 0),
                'subject'   => (string) ($d['email'] ?? ''),
            ];
            return match ($bucket) {
                'error' => $base + ['outcome' => 'error', 'level' => 'attention',
                    'detail' => (string) ($d['message'] ?? '')],
                'license_blocked' => $base + ['outcome' => 'license-blocked', 'level' => 'attention',
                    'detail' => (string) ($d['detail'] ?? '') ?: 'no license seat available'],
                'manual_override' => $base + ['outcome' => 'manual-override', 'level' => 'info',
                    'detail' => 'suspended in Google while golden-active — left alone (never auto-restored)'],
                default => null,
            };
        }
        if ($event === 'result') {
            $ok = !empty($d['ok']);
            $detail = implode(' — ', array_filter([
                trim((string) ($d['detail'] ?? '')),
                trim((string) ($d['message'] ?? '')),
            ], static fn(string $s): bool => $s !== ''));
            return [
                'phase'     => 'apply',
                'person_id' => (int) ($d['person_id'] ?? 0),
                'subject'   => (string) ($d['email'] ?? ''),
                'outcome'   => (string) ($d['action'] ?? ''),
                'level'     => $ok ? 'change' : 'attention',
                'detail'    => $ok ? $detail : ('FAILED' . ($detail !== '' ? ' — ' . $detail : '')),
            ];
        }
        return null;
    }

    /**
     * Classify an AdaxesReconciler 'item' event (one person's decided outcome
     * in one phase: disable / edit / create / groups).
     *
     * @return array{phase:string, person_id:?int, subject:string, outcome:string, level:string, detail:string}|null
     */
    public static function fromAdaxesItem(array $d): ?array
    {
        $outcome = (string) ($d['outcome'] ?? '');
        $level = self::adaxesLevel($outcome);
        if ($level === null) {
            return null;
        }
        return [
            'phase'     => (string) ($d['action'] ?? ''),
            'person_id' => (int) ($d['person_id'] ?? 0),
            'subject'   => (string) ($d['name'] ?? ''),
            'outcome'   => $outcome,
            'level'     => $level,
            'detail'    => (string) ($d['detail'] ?? ''),
        ];
    }

    /**
     * Severity of an Adaxes item outcome, or null for the outcomes too noisy
     * to persist per person (no-ops and not-in-AD skips — the phase counts
     * already say how many).
     */
    private static function adaxesLevel(string $outcome): ?string
    {
        if (in_array($outcome, ['noop', 'skip'], true)) {
            return null;
        }
        if (in_array($outcome, ['error', 'review', 'blocked'], true)) {
            return 'attention';
        }
        // would-expire / would-edit / … : a preview (dry phase or writes OFF),
        // not an applied change; capped: candidate deferred to a later run.
        if (str_starts_with($outcome, 'would-') || $outcome === 'capped') {
            return 'info';
        }
        // expired / edited / moved / created / correlated / rehired /
        // password-set / synced — a change actually applied.
        return 'change';
    }
}
