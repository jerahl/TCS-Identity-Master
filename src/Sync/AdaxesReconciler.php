<?php

declare(strict_types=1);

namespace App\Sync;

use App\Config;
use App\Import\PersonWriter;
use App\Service\AdaxesService;
use App\Service\AdaxesWriter;
use App\Service\AuditService;
use App\Service\GoogleWorkspaceService;
use App\Service\GroupPolicy;
use App\Service\UsernameMinter;
use App\Support\Crypto;
use App\Support\PasswordGenerator;
use PDO;

/**
 * The direct-AD provisioning reconciler: computes desired AD state vs. live AD
 * state for each person and applies the delta (disable / edit / create) through
 * the Adaxes REST API, making IDM — not OneSync — the authoritative writer of
 * Active Directory accounts. See docs/adaxes-provisioning-design.md.
 *
 * Phase-gated and off by default. Nothing is written unless the AdaxesWriter is
 * configured (ADAXES_WRITE_ENABLED=true + a write credential); a --dry-run
 * preview always works and changes nothing. Reads live AD through the existing
 * read-only AdaxesService (verify/search), so it reuses the same correlation.
 *
 * Guardrails (invariants across phases):
 *  1. Link before write — edit/disable only ever act on a person with a linked,
 *     well-formed objectGUID; a search hit without a stored GUID is a correlation
 *     task, routed to review, never a write.
 *  2. Create only when truly new — no linked GUID AND no live AD hit. A hit is
 *     correlated (linked), never recreated; if that existing account is disabled or
 *     expired (a returning employee) it is re-enabled as part of the correlate.
 *  3. Username immutability — a username_locked person is never re-minted; in the
 *     create phase an unlinked person is auto-correlated to the existing AD account
 *     when the match is unambiguous (locked username, or employee number + surname)
 *     — else routed to review — never minted a second identity.
 *  4. Threshold valves — a ratio cap on disables and an absolute cap on creates,
 *     so a truncated feed can't trigger a mass change.
 *  5. Audit everything (audit_log + lifecycle_event), actor system:adaxes_sync.
 *
 * @phpstan-type Item array{person_id:int, name:string, action:string, outcome:string, detail:string}
 */
final class AdaxesReconciler
{
    public const ACTOR = 'system:adaxes_sync';

    /** A well-formed objectGUID — the only kind we treat as a real AD link. */
    private const GUID_RE = '/^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$/';

    private AuditService $audit;
    private PersonWriter $people;
    private GroupPolicy $groups;
    private float $maxDisableRatio;
    private int $disableGuardMin;
    private int $maxCreates;
    private string $emailDomain;
    private string $upnSuffix;
    private string $baseDn;
    private string $parentOu;
    private bool $setPasswordOnCreate;

    /** Optional live-progress callback: fn(string $event, array $data): void. */
    private $log = null;

    /** When non-empty, every phase is restricted to these person_ids (test cohort). */
    private array $restrictPersonIds = [];

    /** Per-run cache: configured group name (lowercased) → ['cn'=>real cn, 'id'=>DN/GUID|null]. */
    private array $groupResolveCache = [];

    /** Per-run cache: school_ids that are transportation locations (null = unresolved). */
    private ?array $transportationSchoolIds = null;

    public function __construct(
        private readonly PDO $db,
        private readonly AdaxesService $read,
        private readonly AdaxesWriter $writer,
        ?AuditService $audit = null,
        ?PersonWriter $people = null,
        ?GroupPolicy $groups = null,
    ) {
        $this->audit  = $audit ?? new AuditService($this->db);
        $this->people = $people ?? new PersonWriter($this->db, $this->audit);
        $this->groups = $groups ?? new GroupPolicy();

        $this->maxDisableRatio = (float) Config::get('ADAXES_WRITE_MAX_DISABLES_RATIO', '0.2');
        $this->disableGuardMin = max(1, (int) Config::get('ADAXES_WRITE_DISABLE_GUARD_MIN', '20'));
        $this->maxCreates      = max(0, (int) Config::get('ADAXES_WRITE_MAX_CREATES', '50'));
        $this->emailDomain     = trim((string) Config::get('AD_EMAIL_DOMAIN', 'tusc.k12.al.us'));
        $this->upnSuffix       = trim((string) (Config::get('AD_UPN_SUFFIX', '') ?: $this->emailDomain));
        // Domain base appended to the (relative) school.ad_ou to form a full
        // container DN, and the shared parent OU every account nests under.
        $this->baseDn          = trim((string) Config::get('AD_BASE_DN', ''), " ,");
        $this->parentOu        = trim((string) Config::get('AD_PARENT_OU', 'OU=Faculty'), " ,");
        // IDM sets the initial password itself on create because Adaxes Business
        // Rules don't fire on REST events. On by default; turn off only if a create
        // Business Rule is (re-)authored to own the password.
        $this->setPasswordOnCreate = Config::bool('ADAXES_SET_PASSWORD_ON_CREATE', true);
    }

    /**
     * Run the reconciler.
     *
     * @param list<string> $phases any of 'disable','edit','create','groups'
     * @param callable(string,array<string,mixed>):void|null $log optional live-progress
     *        callback: fires 'phase' (with the phase name + people count) as each phase
     *        starts and 'item' as each person's outcome is decided. Lets a CLI stream
     *        progress instead of waiting for the batch summary.
     * @param list<int> $onlyPersonIds restrict EVERY phase to these person_ids — for
     *        testing a handful of accounts live (create/edit/disable/groups + the
     *        Adaxes Business Rules that fire on a real write) without touching anyone
     *        else. Empty = the whole population.
     * @return array<string,mixed>
     */
    public function run(bool $dryRun = true, array $phases = ['disable', 'edit', 'create', 'groups'], ?int $limit = null, ?callable $log = null, array $onlyPersonIds = []): array
    {
        $this->restrictPersonIds = array_values(array_unique(array_map('intval', $onlyPersonIds)));
        $this->log = $log;
        $writeEnabled = $this->writer->configured();
        $apply = !$dryRun && $writeEnabled;

        $result = [
            'dry_run'       => $dryRun,
            'write_enabled' => $writeEnabled,
            'applied'       => $apply,
            'phases'        => $phases,
            'errors'        => 0,
            'notes'         => [],
        ];

        if (!$this->read->configured()) {
            $result['notes'][] = 'Adaxes read service is not configured — cannot verify live AD. Set ADAXES_BASE_URL and a token.';
            return $result;
        }
        if (!$dryRun && !$writeEnabled) {
            $result['notes'][] = 'Adaxes writes are off (ADAXES_WRITE_ENABLED=false) — nothing was written. Use --dry-run to preview.';
        }

        if (in_array('disable', $phases, true)) {
            $result['disable'] = $this->runDisable($apply, $limit);
            $result['errors'] += $result['disable']['errors'];
        }
        if (in_array('edit', $phases, true)) {
            $result['edit'] = $this->runEdit($apply, $limit);
            $result['errors'] += $result['edit']['errors'];
        }
        if (in_array('create', $phases, true)) {
            $result['create'] = $this->runCreate($apply, $limit);
            $result['errors'] += $result['create']['errors'];
        }
        if (in_array('groups', $phases, true)) {
            $result['groups'] = $this->runGroups($apply, $limit);
            $result['errors'] += $result['groups']['errors'];
        }

        return $result;
    }

    // ---- Phase 1: disable (expire leavers) ----------------------------------

    /**
     * Expire leavers: people whose golden status is 'disabled', who have a linked
     * objectGUID, and whose live AD account is not already expired as intended.
     * Rather than flipping accountDisabled, IDM sets the account's expiration date
     * to the person's end date when one is set (otherwise today) and stamps
     * `description` with "Account expired set by TCS-IDM on {date}" (the run date,
     * recording when IDM acted) so the reason is visible in AD. A ratio valve
     * blocks a mass change from a truncated feed.
     *
     * No-op (no description churn) when the account is already expired on exactly
     * the desired date, or — when there is no end date to honor — already expired
     * on any past-or-today date.
     *
     * @return array{blocked:bool,candidates:int,applied:int,noop:int,skipped:int,errors:int,items:list<Item>}
     */
    private function runDisable(bool $apply, ?int $limit): array
    {
        $out = ['blocked' => false, 'candidates' => 0, 'applied' => 0, 'noop' => 0, 'skipped' => 0, 'errors' => 0, 'items' => []];

        $people = $this->fetchPeople("status = 'disabled'", $limit);
        $this->emit('phase', ['phase' => 'disable', 'total' => count($people)]);
        $today = gmdate('Y-m-d');
        $candidates = [];   // [personId => [person, guid, name, expiry, description]]

        foreach ($people as $p) {
            $pid = (int) $p['person_id'];
            $name = self::displayName($p);
            $sourceIds = $this->sourceIdsFor($pid);
            $guid = self::linkedGuid($sourceIds);

            if ($guid === null) {
                $out['skipped']++;
                $this->item($out, $pid, $name, 'disable', 'review', 'no linked objectGUID — correlation task, not a write');
                continue;
            }

            $env = $this->read->verify($p, $sourceIds);
            if (!$env['ok']) {
                $out['errors']++;
                $this->item($out, $pid, $name, 'disable', 'error', (string) $env['error']);
                continue;
            }
            if (!$env['found']) {
                $out['skipped']++;
                $this->item($out, $pid, $name, 'disable', 'skip', 'no live AD account found');
                continue;
            }

            // Desired expiry date: the person's position end date when set,
            // otherwise today (normalized to Y-m-d so it compares against the
            // account's decoded accountExpires).
            $desiredDate = $today;
            $endDate = trim((string) ($p['end_date'] ?? ''));
            if ($endDate !== '') {
                $ts = strtotime($endDate . ' 00:00:00 UTC');
                if ($ts !== false) {
                    $desiredDate = gmdate('Y-m-d', $ts);
                }
            }

            // Already handled → no-op (no description churn): expired on exactly
            // the desired date, or — with no end date to honor — already expired
            // on any past-or-today date.
            $expiry = AdaxesService::accountExpiryFromEnvelope($env);
            $realExpiry = $expiry !== null && $expiry !== 'Never';
            if ($realExpiry && ($expiry === $desiredDate || ($endDate === '' && $expiry <= $today))) {
                $out['noop']++;
                $this->item($out, $pid, $name, 'disable', 'noop', 'AD account already expired (' . $expiry . ')');
                continue;
            }
            $adAttrs = is_array($env['attributes'] ?? null) ? $env['attributes'] : [];
            $candidates[$pid] = [
                'guid'        => $guid,
                'name'        => $name,
                'expiry'      => $expiry,
                'desiredDate' => $desiredDate,
                'description' => trim((string) ($adAttrs['description'] ?? '')),
            ];
        }

        $out['candidates'] = count($candidates);
        if ($candidates === []) {
            return $out;
        }

        // Ratio valve: block a mass-expire relative to the linked population.
        $linkedTotal = $this->countLinkedAdAccounts();
        if ($linkedTotal >= $this->disableGuardMin && ($out['candidates'] / $linkedTotal) > $this->maxDisableRatio) {
            $out['blocked'] = true;
            foreach ($candidates as $pid => $c) {
                $this->item($out, $pid, $c['name'], 'disable', 'blocked',
                    sprintf('would expire %d of %d linked (> %.0f%%) — blocked; investigate the feed', $out['candidates'], $linkedTotal, $this->maxDisableRatio * 100));
            }
            return $out;
        }

        // The description records WHEN IDM acted (the run date), independent of the
        // expiry date it sets (which may be a past/future end date).
        $desc = 'Account expired set by TCS-IDM on ' . $today;

        foreach ($candidates as $pid => $c) {
            $desiredDate = $c['desiredDate'];
            $attrs = [
                'accountExpires' => (string) self::accountExpiresIso($desiredDate),
                'description'    => $desc,
            ];
            // Dry-run report: show what AD holds now vs. what would be written.
            $currentForReport = [
                'accountexpires' => ($c['expiry'] === null || $c['expiry'] === '') ? '' : (string) $c['expiry'],
                'description'    => $c['description'],
            ];
            if (!$apply) {
                $this->item($out, $pid, $c['name'], 'disable', 'would-expire',
                    self::changeSummary(['accountExpires' => $desiredDate, 'description' => $desc], $currentForReport));
                continue;
            }
            $res = $this->writer->modify($c['guid'], $attrs);
            if (!$res['ok']) {
                $out['errors']++;
                $this->item($out, $pid, $c['name'], 'disable', 'error', (string) $res['error']);
                continue;
            }
            $this->audit->log('person', $pid, 'update',
                ['accountExpires' => $c['expiry'] ?? 'unset'],
                ['accountExpires' => $desiredDate, 'description' => $desc], self::ACTOR);
            $this->audit->lifecycle($pid, 'disable', ['summary' => 'AD account expired via Adaxes as of ' . $desiredDate . ' (objectGUID ' . $c['guid'] . ').'], self::ACTOR);
            $out['applied']++;
            $this->item($out, $pid, $c['name'], 'disable', 'expired', 'accountExpires set to ' . $desiredDate . '; description updated');
        }

        return $out;
    }

    // ---- Phase 2: edit ------------------------------------------------------

    /**
     * Push golden→AD attribute drift for active/pending people with a linked
     * objectGUID: UPN + mail (from the identity comparison), the person's NAME
     * (givenName / sn / displayName — pushed immediately when the golden name
     * changes, e.g. a marriage-related last-name change, so AD reflects it as
     * fast as Google and PowerSchool do), and the operational mappings. The
     * username/email/UPN *rename* deliberately does NOT happen here — that is
     * the RenameService scheduled cutover (RENAME_NOTICE_DAYS): the golden
     * username/email/upn only move at cutover, so until then this phase sees no
     * mail/UPN drift and only the name attributes change. Never touches
     * sAMAccountName (immutable) and never blanks an AD value from an empty
     * golden field.
     *
     * @return array{applied:int,noop:int,skipped:int,errors:int,items:list<Item>}
     */
    private function runEdit(bool $apply, ?int $limit): array
    {
        $out = ['applied' => 0, 'noop' => 0, 'skipped' => 0, 'errors' => 0, 'items' => []];

        $people = $this->fetchPeople("status IN ('active','pending')", $limit);
        $this->emit('phase', ['phase' => 'edit', 'total' => count($people)]);
        foreach ($people as $p) {
            $pid = (int) $p['person_id'];
            $name = self::displayName($p);
            $sourceIds = $this->sourceIdsFor($pid);
            $guid = self::linkedGuid($sourceIds);
            if ($guid === null) {
                continue; // no link → not an edit target (create handles net-new)
            }

            $env = $this->read->verify($p, $sourceIds);
            if (!$env['ok']) {
                $out['errors']++;
                $this->item($out, $pid, $name, 'edit', 'error', (string) $env['error']);
                continue;
            }
            if (!$env['found']) {
                $out['skipped']++;
                $this->item($out, $pid, $name, 'edit', 'skip', 'no live AD account found');
                continue;
            }

            $adAttrs = is_array($env['attributes'] ?? null) ? $env['attributes'] : [];
            $attrs = self::editDelta($env['comparison'] ?? []);
            // Name drift: a legal name change pushes givenName/sn/displayName right
            // away; the username/email/UPN rename stays on the scheduled cutover.
            $attrs += self::nameDrift($p, $adAttrs);
            // Operational drift beyond the identity comparison: title/department and
            // their mirrors (department drives the per-school Everyone groups, so a
            // school move MUST propagate or downstream group logic breaks).
            $attrs += $this->operationalDrift($p, $adAttrs);
            // OU drift: the container the person SHOULD live in vs. where AD has them
            // (e.g. a bus aide created under a building that must move to OU=trans).
            $moveTo = $this->ouMoveTarget($p, $adAttrs);

            $acted = false;

            if ($attrs !== []) {
                $acted = true;
                if (!$apply) {
                    $this->item($out, $pid, $name, 'edit', 'would-edit', self::changeSummary($attrs, $adAttrs));
                } else {
                    $res = $this->writer->modify($guid, $attrs);
                    if (!$res['ok']) {
                        $out['errors']++;
                        $this->item($out, $pid, $name, 'edit', 'error', (string) $res['error']);
                    } else {
                        $this->audit->log('person', $pid, 'update', ['ad_attrs' => 'drift'], $res['changed'], self::ACTOR);
                        $this->audit->lifecycle($pid, 'update', ['summary' => 'AD attributes updated via Adaxes: ' . self::attrsSummary($res['changed']) . '.'], self::ACTOR);
                        $out['applied']++;
                        $this->item($out, $pid, $name, 'edit', 'edited', self::attrsSummary($res['changed']));
                    }
                }
            }

            if ($moveTo !== null) {
                $acted = true;
                if (!$apply) {
                    $currentContainer = self::parentDn(trim((string) ($adAttrs['distinguishedname'] ?? '')));
                    $this->item($out, $pid, $name, 'edit', 'would-move',
                        'move ' . ($currentContainer !== '' ? $currentContainer : '(unknown)') . ' → ' . $moveTo);
                } else {
                    $res = $this->writer->move($guid, $moveTo);
                    if (!$res['ok']) {
                        $out['errors']++;
                        $this->item($out, $pid, $name, 'edit', 'error', 'move: ' . (string) $res['error']);
                    } else {
                        $this->audit->log('person', $pid, 'update', ['ad_ou' => 'move'], ['container' => $moveTo], self::ACTOR);
                        $this->audit->lifecycle($pid, 'update', ['summary' => 'AD account moved to ' . $moveTo . ' via Adaxes.'], self::ACTOR);
                        $out['applied']++;
                        $this->item($out, $pid, $name, 'edit', 'moved', 'to ' . $moveTo);
                    }
                }
            }

            if (!$acted) {
                $out['noop']++;
            }
        }

        return $out;
    }

    // ---- Phase 3: create ----------------------------------------------------

    /**
     * Create AD accounts for true net-new hires: active/pending people with no
     * linked objectGUID and no live AD search hit. Mints the username (unless one
     * is already assigned), creates the account in the building's OU, links the
     * returned objectGUID, and stamps the golden record (username locked, email,
     * upn, activated). Absolute per-run create cap.
     *
     * @return array{applied:int,skipped:int,review:int,errors:int,capped:int,items:list<Item>}
     */
    private function runCreate(bool $apply, ?int $limit): array
    {
        $out = ['applied' => 0, 'correlated' => 0, 'rehired' => 0, 'skipped' => 0, 'review' => 0, 'errors' => 0, 'capped' => 0, 'items' => []];

        // Only people with NO active 'ad' crosswalk row at all.
        $people = $this->fetchPeople(
            "status IN ('active','pending')
             AND p.person_id NOT IN (SELECT person_id FROM person_source_id WHERE system = 'ad' AND is_active = 1)",
            $limit
        );
        $this->emit('phase', ['phase' => 'create', 'total' => count($people)]);

        foreach ($people as $p) {
            $pid = (int) $p['person_id'];
            $name = self::displayName($p);
            $sourceIds = $this->sourceIdsFor($pid);

            // Look the person up in live AD once. A hit means the account already
            // exists — a returning employee whose old account was disabled/expired,
            // a locked-but-unlinked identity, or a prior OneSync mint — so we
            // CORRELATE (link, and re-enable when it was locked out), never create a
            // duplicate. Only a clean miss is a true net-new hire. This is also the
            // net-new confirmation (an unambiguous "nothing there") the create
            // guardrail requires.
            $env = $this->read->verify($p, $sourceIds);
            if (!$env['ok']) {
                $out['errors']++;
                $this->item($out, $pid, $name, 'create', 'error', (string) $env['error']);
                continue;
            }
            if ($env['found']) {
                $this->correlate($out, $pid, $name, $p, $apply, $env);
                continue;
            }

            // No live account. Immutability: a person who already carries a locked
            // username but has no AD link is an existing identity we could not find
            // — never mint a second account; leave it for a human to correlate.
            if (!empty($p['username_locked'])) {
                $out['review']++;
                $this->item($out, $pid, $name, 'create', 'review', 'locked username but no matching AD account found — investigate/correlate manually');
                continue;
            }

            if ($this->baseDn === '') {
                $out['skipped']++;
                $this->item($out, $pid, $name, 'create', 'review', 'AD_BASE_DN is not set — cannot form a full container DN');
                continue;
            }
            $placement = $this->placement($p);
            if ($placement === null) {
                $out['skipped']++;
                $this->item($out, $pid, $name, 'create', 'review', 'no AD OU for the building (school.ad_ou) — cannot place');
                continue;
            }
            $ou = $placement;

            // Mint (or reuse a pre-assigned, locked username).
            try {
                $username = $this->resolveUsername($p);
            } catch (\InvalidArgumentException $e) {
                $out['skipped']++;
                $this->item($out, $pid, $name, 'create', 'review', $e->getMessage());
                continue;
            }
            $email = UsernameMinter::emailFor($username, $this->emailDomain);
            $upn   = UsernameMinter::emailFor($username, $this->upnSuffix);
            $attrs = $this->createAttrs($p, $username, $email, $upn);
            $attrs['cn'] = $this->uniqueCn($p, $username);

            if (!$apply) {
                $this->item($out, $pid, $name, 'create', 'would-create', "as {$username} ({$email}) in {$ou}");
                continue;
            }

            // Per-run absolute cap on real creates.
            if ($out['applied'] >= $this->maxCreates) {
                $out['capped']++;
                $this->item($out, $pid, $name, 'create', 'capped', "per-run create cap ({$this->maxCreates}) reached — deferred");
                continue;
            }

            $res = $this->writer->create($ou, $attrs);
            if (!$res['ok']) {
                $out['errors']++;
                $this->item($out, $pid, $name, 'create', 'error', (string) $res['error']);
                continue;
            }

            // Resolve the GUID: prefer the create response; fall back to a search
            // for the just-created account so the link is never lost.
            $guid = $res['guid'];
            if ($guid === null) {
                $lookup = $this->read->search(['username' => $username]);
                $guid = $lookup['ok'] && $lookup['found'] ? AdaxesService::goldenCandidate($lookup)['guid'] : null;
            }

            $this->people->linkAdAccount($pid, [
                'guid' => $guid, 'username' => $username, 'email' => $email, 'upn' => $upn,
            ], self::ACTOR);
            $this->audit->lifecycle($pid, 'create', ['summary' => "AD account created via Adaxes as {$username} in {$ou}."], self::ACTOR);

            $out['applied']++;
            $detail = $guid !== null ? "created as {$username}, linked {$guid}" : "created as {$username} — GUID NOT resolved, needs correlation";
            if ($guid === null) {
                $out['errors']++;
            }
            $this->item($out, $pid, $name, 'create', $guid !== null ? 'created' : 'created-unlinked', $detail);

            // Business Rules don't fire on REST events, so set the initial password
            // (+ must-change-at-logon) ourselves and record it on the golden record.
            // Only when the GUID resolved — an unlinked account can't be targeted.
            if ($guid !== null) {
                $this->setInitialPassword($out, $pid, $name, $guid);
            }
        }

        return $out;
    }

    /**
     * Set a fresh initial password on a just-created account and record it
     * (encrypted) on the golden record. Adaxes Business Rules do NOT fire on REST
     * API events, so the generated-password + "must change at next logon" that
     * OneSync's create rule applied is done here instead. Failures don't roll back
     * the create — they count as errors and surface for a human, since a created
     * account with no usable/recorded password needs attention. Never logs the
     * secret. No-op when disabled via ADAXES_SET_PASSWORD_ON_CREATE=false.
     *
     * @param array<string,mixed> $out phase accumulator (by ref)
     */
    private function setInitialPassword(array &$out, int $pid, string $name, string $guid): void
    {
        if (!$this->setPasswordOnCreate) {
            return;
        }
        // Fail loud rather than set a password we can't store: without the
        // encryption key the value couldn't be recorded for the new hire.
        if (!Crypto::configured()) {
            $out['errors']++;
            $this->item($out, $pid, $name, 'create', 'error',
                'account created but initial password NOT set — CREDENTIAL_ENC_KEY is unset, so it could not be stored securely');
            return;
        }

        $password = PasswordGenerator::generate();
        $res = $this->writer->resetPassword($guid, $password, true);
        if (!$res['ok']) {
            $out['errors']++;
            $this->item($out, $pid, $name, 'create', 'error',
                'account created but initial password NOT set: ' . (string) $res['error']);
            return;
        }

        try {
            $this->people->recordInitialPassword($pid, $password, self::ACTOR);
        } catch (\Throwable $e) {
            $out['errors']++;
            $this->item($out, $pid, $name, 'create', 'error',
                'initial password set in AD but NOT recorded on the golden record: ' . $e->getMessage());
            return;
        }

        $this->item($out, $pid, $name, 'create', 'password-set', 'initial password set (must change at next logon)');
    }

    /**
     * Correlate a person to the existing AD account the create-phase lookup found:
     * when the match is UNAMBIGUOUS, link the objectGUID into the crosswalk
     * (adopting username/email/upn, activating a pending person) instead of routing
     * to review every run — and, when that account is currently locked out
     * (disabled, or expired in the past), RE-ENABLE it. That locked-out case is the
     * returning employee: a fresh golden record whose old AD account still exists
     * but was expired/disabled when they left. We reactivate the original account
     * rather than minting a second one.
     *
     * "Unambiguous" = an objectGUID is present AND a strong identity agreement:
     * either the person's locked golden username equals the account's
     * sAMAccountName (with mail agreeing when both are set), OR — for a person not
     * yet minted (the typical returning employee) — the employee number matches the
     * account's employeeID and the surname agrees. Anything short of that still
     * goes to review for a human; we never link the wrong account, and never create.
     *
     * @param array<string,mixed> $out phase accumulator (by ref)
     * @param array<string,mixed> $p   person row
     * @param array<string,mixed> $env the verify() envelope for this person (found)
     */
    private function correlate(array &$out, int $pid, string $name, array $p, bool $apply, array $env): void
    {
        $username    = trim((string) ($p['username'] ?? ''));
        $goldenEmail = trim((string) ($p['email'] ?? ''));
        $employeeId  = trim((string) ($p['employee_id'] ?? ''));
        $lastName    = trim((string) ($p['last_name'] ?? ''));

        $cand  = AdaxesService::goldenCandidate($env);
        $attrs = is_array($env['attributes'] ?? null) ? $env['attributes'] : [];
        $adEmployeeId = trim((string) ($attrs['employeeid'] ?? ''));
        $adSurname    = trim((string) ($attrs['sn'] ?? ''));

        // Two ways to be confident: the locked username matches (email agreeing), or
        // — for a not-yet-minted returning employee — the employee number + surname.
        $byUsername = $username !== '' && self::eqCi($cand['username'], $username)
            && ($goldenEmail === '' || $cand['email'] === '' || self::eqCi($cand['email'], $goldenEmail));
        $byEmployee = $employeeId !== '' && $adEmployeeId !== '' && self::eqCi($adEmployeeId, $employeeId)
            && $lastName !== '' && $adSurname !== '' && self::eqCi($adSurname, $lastName);
        $unambiguous = $cand['guid'] !== null && ($byUsername || $byEmployee);

        // Is the existing account locked out — the returning-employee signal? Either
        // accountDisabled is set, or accountExpires is a real past date. "Unknown"
        // (neither attribute returned) is treated as not-locked-out (never assume).
        $enabled = AdaxesService::accountEnabledFromEnvelope($env);
        $expiry  = AdaxesService::accountExpiryFromEnvelope($env);
        $today   = gmdate('Y-m-d');
        $isDisabled = $enabled === false;
        $isExpired  = $expiry !== null && $expiry !== 'Never' && $expiry < $today;
        $lockedOut  = $isDisabled || $isExpired;

        if (!$unambiguous) {
            $out['review']++;
            if ($cand['guid'] === null) {
                $detail = 'AD account found but no objectGUID returned — correlate manually';
            } elseif ($lockedOut) {
                $detail = sprintf(
                    'returning employee? existing AD account (%s) is %s but does not confidently match golden (username %s / employee id %s) — correlate & re-enable manually',
                    $cand['username'] ?: '—', $isDisabled ? 'disabled' : 'expired', $username ?: '—', $employeeId ?: '—'
                );
            } else {
                $detail = sprintf('AD account (%s / %s) does not confidently match golden (%s / %s) — correlate, do not create',
                    $cand['username'] ?: '—', $cand['email'] ?: '—', $username ?: '—', $goldenEmail ?: '—');
            }
            $this->item($out, $pid, $name, 'create', 'review', $detail);
            return;
        }

        if (!$apply) {
            if ($lockedOut) {
                $this->item($out, $pid, $name, 'create', 'would-rehire',
                    'returning employee — re-enable (' . ($isDisabled ? 'disabled' : 'expired') . ') and link existing AD account ' . $cand['guid']);
            } else {
                $this->item($out, $pid, $name, 'create', 'would-correlate', 'link existing AD account ' . $cand['guid']);
            }
            return;
        }

        // Adopt the identity + link the GUID (activates a pending person).
        $this->people->linkAdAccount($pid, [
            'guid'     => $cand['guid'],
            'username' => $cand['username'],
            'email'    => $goldenEmail !== '' ? $goldenEmail : $cand['email'],
            'upn'      => trim((string) ($p['upn'] ?? '')) !== '' ? (string) $p['upn'] : $cand['upn'],
        ], self::ACTOR);

        if (!$lockedOut) {
            $this->audit->lifecycle($pid, 'update',
                ['summary' => 'Correlated to existing AD account (objectGUID ' . $cand['guid'] . ') — linked, not recreated.'], self::ACTOR);
            $out['correlated']++;
            $this->item($out, $pid, $name, 'create', 'correlated', 'linked existing AD account ' . $cand['guid']);
            return;
        }

        // Returning employee: re-enable so the reactivated account isn't locked out.
        $this->rehire($out, $pid, $name, $p, $cand['guid'], $isDisabled, $isExpired, $expiry, $today);
    }

    /**
     * Reactivate a returning employee's existing (linked) AD account: clear the
     * disabled flag, reset the expiration (to a future end date when one is set,
     * else clear it back to "never"), and stamp the description. Called only after
     * a confident correlate has already linked the objectGUID.
     *
     * Clearing accountDisabled and setting a future accountExpires are strict — a
     * failure surfaces as a per-person error. Clearing the expiration to "never" is
     * best-effort (the Timestamp-clear shape is Adaxes-version-specific) so it can
     * never turn a successful reactivation into a reported failure.
     *
     * @param array<string,mixed> $out phase accumulator (by ref)
     * @param array<string,mixed> $p   person row
     */
    private function rehire(array &$out, int $pid, string $name, array $p, string $guid, bool $isDisabled, bool $isExpired, ?string $expiry, string $today): void
    {
        $stamp = 'Account re-enabled by TCS-IDM on ' . $today . ' (returning employee)';
        $notes = [];

        if ($isDisabled) {
            $res = $this->writer->enable($guid);
            if (!$res['ok']) {
                $out['errors']++;
                $this->item($out, $pid, $name, 'create', 'error', 'linked ' . $guid . ' but re-enable failed — enable: ' . (string) $res['error']);
                return;
            }
            $notes[] = 'cleared accountDisabled';
        }

        // Reset the expiry so a prior expire-leaver date doesn't immediately re-lock
        // the reactivated account: to the future end date when one is set, otherwise
        // clear it to "never".
        $endDate   = trim((string) ($p['end_date'] ?? ''));
        $futureIso = ($endDate !== '' && $endDate >= $today) ? self::accountExpiresIso($endDate) : null;

        if ($futureIso !== null) {
            $res = $this->writer->modify($guid, ['accountExpires' => $futureIso, 'description' => $stamp]);
            if (!$res['ok']) {
                $out['errors']++;
                $this->item($out, $pid, $name, 'create', 'error', 'linked ' . $guid . ' but re-enable failed — modify: ' . (string) $res['error']);
                return;
            }
            $notes[] = 'accountExpires set to ' . $endDate;
        } else {
            $res = $this->writer->modify($guid, ['description' => $stamp]);
            if (!$res['ok']) {
                $out['errors']++;
                $this->item($out, $pid, $name, 'create', 'error', 'linked ' . $guid . ' but re-enable failed — modify: ' . (string) $res['error']);
                return;
            }
            if ($isExpired) {
                // Best-effort clear-to-never (never fails the reactivation).
                $clr = $this->writer->clearExpiration($guid);
                $notes[] = $clr['ok'] ? 'accountExpires cleared (never)' : 'accountExpires clear skipped (' . (string) $clr['error'] . ')';
            }
        }

        $this->audit->log('person', $pid, 'update',
            ['accountDisabled' => $isDisabled ? 'true' : 'false', 'accountExpires' => $expiry ?? 'unset'],
            ['reenabled' => true], self::ACTOR);
        $this->audit->lifecycle($pid, 'create',
            ['summary' => "Re-enabled returning employee's existing AD account (objectGUID " . $guid . ') — ' . implode('; ', $notes) . '; linked, not recreated.'], self::ACTOR);
        $out['rehired']++;
        $this->item($out, $pid, $name, 'create', 'rehired', 're-enabled & linked existing AD account ' . $guid . ' — ' . implode('; ', $notes));
    }

    /** Case-insensitive equality of two trimmed strings. */
    private static function eqCi(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    // ---- Phase 4: groups ----------------------------------------------------

    /**
     * Reconcile AD group membership for active/pending people with a linked
     * objectGUID: compute the desired groups (GroupPolicy) and diff against the
     * live `memberOf`, adding missing groups and removing memberships IDM manages
     * that the person no longer qualifies for. Groups outside the managed set are
     * never touched. Requires the group-membership write endpoints
     * (ADAXES_GROUP_ADD_PATH / _REMOVE_PATH) to actually write — until they are
     * set the phase reports the intended add/remove and applies nothing.
     *
     * @return array{applied:int,added:int,removed:int,noop:int,skipped:int,errors:int,items:list<Item>}
     */
    private function runGroups(bool $apply, ?int $limit): array
    {
        $out = ['applied' => 0, 'added' => 0, 'removed' => 0, 'noop' => 0, 'skipped' => 0, 'errors' => 0, 'items' => []];

        $people = $this->fetchPeople("status IN ('active','pending')", $limit);
        $this->emit('phase', ['phase' => 'groups', 'total' => count($people)]);

        // The exact set of groups IDM owns (fixed groups + every building's
        // Everyone group). Removals are confined to this set, so custom/manual
        // groups — anything not the policy could assign — are never disturbed.
        $managed = $this->groups->managedGroups($this->allSchoolTokens());

        foreach ($people as $p) {
            $pid = (int) $p['person_id'];
            $name = self::displayName($p);
            $guid = self::linkedGuid($this->sourceIdsFor($pid));
            if ($guid === null) {
                continue; // no link → create/correlation territory, not groups
            }

            $desired = $this->groups->desiredGroups(
                (string) ($p['title'] ?? ''),
                (string) ($p['person_type'] ?? ''),
                $this->schoolToken($p),
                $this->isTransportation($p),
                (string) ($p['raptor_group_override'] ?? ''),
            );

            $live = $this->read->memberOf($guid);
            if (!$live['ok']) {
                $out['errors']++;
                $this->item($out, $pid, $name, 'groups', 'error', (string) $live['error']);
                continue;
            }
            if (!$live['found']) {
                $out['skipped']++;
                $this->item($out, $pid, $name, 'groups', 'skip', 'no live AD account found');
                continue;
            }

            // Compare by cn (case-insensitive). Keep the DN for removals.
            $liveByCn = [];
            foreach ($live['groups'] as $dn) {
                $cn = self::cnOf($dn);
                if ($cn !== '') {
                    $liveByCn[strtolower($cn)] = ['cn' => $cn, 'dn' => $dn];
                }
            }
            // Resolve each desired group to its REAL cn (as AD stores it) so the
            // comparison matches what memberOf reports even when the configured
            // name is the group's sAMAccountName/display. Keep the resolved id for
            // the add so we don't look it up twice.
            $desiredByCn = [];   // lc real cn → configured name
            $desiredId   = [];   // lc real cn → resolved DN/GUID (or null)
            foreach ($desired as $name) {
                $g = $this->resolveGroup($name);
                $lc = strtolower($g['cn']);
                $desiredByCn[$lc] = $name;
                $desiredId[$lc]   = $g['id'];
            }

            // Add: desired groups the account isn't in yet.
            $toAdd = [];   // lc real cn → configured name
            foreach ($desiredByCn as $lc => $name) {
                if (!isset($liveByCn[$lc])) {
                    $toAdd[$lc] = $name;
                }
            }
            // Remove: managed groups the account is in but no longer qualifies for.
            $toRemove = [];
            foreach ($liveByCn as $lc => $g) {
                if (!isset($desiredByCn[$lc]) && isset($managed[$lc])) {
                    $toRemove[] = $g;
                }
            }

            if ($toAdd === [] && $toRemove === []) {
                $out['noop']++;
                continue;
            }

            $summary = trim(($toAdd !== [] ? '+' . implode(',+', array_values($toAdd)) : '') . ' ' . ($toRemove !== [] ? '-' . implode(',-', array_column($toRemove, 'cn')) : ''));

            if (!$apply) {
                $this->item($out, $pid, $name, 'groups', 'would-sync', $summary);
                continue;
            }

            $changed = 0;
            $errs = [];
            foreach ($toAdd as $lc => $name) {
                // The group's DN/GUID was resolved during the comparison above.
                $groupId = $desiredId[$lc] ?? null;
                if ($groupId === null) {
                    $errs[] = "add {$name}: group not found in AD (cannot resolve to a DN)";
                    continue;
                }
                $r = $this->writer->addToGroup($groupId, $guid);
                if ($r['ok']) {
                    $out['added']++;
                    $changed++;
                } else {
                    $errs[] = "add {$name}: " . $r['error'];
                }
            }
            foreach ($toRemove as $g) {
                $r = $this->writer->removeFromGroup($g['dn'], $guid);
                if ($r['ok']) {
                    $out['removed']++;
                    $changed++;
                } else {
                    $errs[] = "remove {$g['cn']}: " . $r['error'];
                }
            }

            if ($errs !== []) {
                $out['errors']++;
                $this->item($out, $pid, $name, 'groups', 'error', implode('; ', $errs));
                continue;
            }
            $this->audit->log('person', $pid, 'update', ['ad_groups' => 'drift'], ['added' => array_values($toAdd), 'removed' => array_column($toRemove, 'cn')], self::ACTOR);
            $this->audit->lifecycle($pid, 'update', ['summary' => 'AD group membership synced via Adaxes: ' . $summary . '.'], self::ACTOR);
            $out['applied']++;
            $this->item($out, $pid, $name, 'groups', 'synced', $summary);
        }

        return $out;
    }

    /**
     * Resolve a configured group name to its LIVE AD identity: the real cn (as AD
     * stores it, taken from the object's DN) and the identifier the group-member
     * API needs (DN, else objectGUID). Cached per run.
     *
     * Comparing membership by this real cn — not the configured name — is what
     * makes the groups phase idempotent: a group whose configured name is its
     * sAMAccountName or display name rather than its cn would otherwise be added
     * successfully yet never match on the next `memberOf` read (whose DNs carry
     * the cn), so IDM would re-add it to every user on every run. Falls back to
     * the given name with a null id when the group can't be found (the add then
     * reports a clear "not found" instead of looping).
     *
     * @return array{cn:string, id:?string}
     */
    private function resolveGroup(string $name): array
    {
        $key = strtolower(trim($name));
        if (array_key_exists($key, $this->groupResolveCache)) {
            return $this->groupResolveCache[$key];
        }
        $res = $this->read->findGroup($name);
        if (($res['ok'] ?? false) && ($res['found'] ?? false)) {
            $dn  = trim((string) ($res['dn'] ?? ''));
            $cn  = $dn !== '' ? self::cnOf($dn) : '';
            $id  = trim((string) ($res['id'] ?? ''));
            $out = ['cn' => $cn !== '' ? $cn : $name, 'id' => $id !== '' ? $id : null];
        } else {
            $out = ['cn' => $name, 'id' => null];
        }
        return $this->groupResolveCache[$key] = $out;
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * The building OU token used for the per-school Everyone group — the leftmost
     * RDN value of school.ad_ou (e.g. "OU=STC,OU=CO" → "STC", "OU=CO" → "CO").
     * '' when the person has no resolvable building.
     *
     * @param array<string,mixed> $p
     */
    private function schoolToken(array $p): string
    {
        $adOu = $this->schoolRow($p)['ad_ou'];
        if ($adOu === '') {
            return '';
        }
        return self::ouToken($adOu);
    }

    /**
     * The building OU token for every school on file — the domain over which the
     * per-school Everyone groups are "managed". Used to bound group removals.
     *
     * @return list<string>
     */
    private function allSchoolTokens(): array
    {
        $tokens = [];
        foreach ($this->db->query("SELECT ad_ou FROM school WHERE ad_ou IS NOT NULL AND ad_ou <> ''")->fetchAll() as $row) {
            $token = self::ouToken((string) ($row['ad_ou'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
        return array_keys($tokens);
    }

    /** Leftmost RDN value of a (possibly multi-level) relative OU, sans "OU=". */
    private static function ouToken(string $adOu): string
    {
        $first = trim(explode(',', trim($adOu, ' ,'))[0]);
        return trim((string) preg_replace('/^OU=/i', '', $first));
    }

    /** The cn (leftmost RDN value) of a distinguishedName, or '' if not a DN. */
    private static function cnOf(string $dn): string
    {
        $first = trim(explode(',', $dn)[0]);
        if (stripos($first, 'CN=') === 0) {
            return trim(substr($first, 3));
        }
        return $first; // already a bare cn
    }

    /**
     * The username to use on create: an already-assigned (locked) username is
     * reused as-is (immutability — never re-mint); otherwise mint a fresh one,
     * testing candidates against both the DB and live AD.
     *
     * @param array<string,mixed> $p
     */
    private function resolveUsername(array $p): string
    {
        $existing = trim((string) ($p['username'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }
        return UsernameMinter::mint(
            (string) ($p['first_name'] ?? ''),
            (string) ($p['last_name'] ?? ''),
            fn(string $candidate): bool => $this->usernameTaken($candidate)
        );
    }

    /** A candidate collides if the DB ledger holds it OR live AD already has it. */
    private function usernameTaken(string $candidate): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM person WHERE LOWER(username) = LOWER(:u) LIMIT 1');
        $stmt->execute([':u' => $candidate]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
        $ad = $this->read->search(['username' => $candidate]);
        // On an AD lookup error, be conservative and treat as taken so we never
        // mint a name we couldn't verify as free.
        return !$ad['ok'] || $ad['found'];
    }

    /**
     * The identity attributes IDM sends on create — the identity core only.
     * Everything operational (home dir, groups, licensing) is left to Adaxes
     * Business Rules that fire on the create. The initial password is the
     * exception — Business Rules don't fire on REST events, so it's set right
     * after the create by setInitialPassword(), not here.
     *
     * @param array<string,mixed> $p
     * @return array<string,string>
     */
    private function createAttrs(array $p, string $username, string $email, string $upn): array
    {
        $first = trim((string) ($p['first_name'] ?? ''));
        $last  = trim((string) ($p['last_name'] ?? ''));
        $display = trim((string) ($p['preferred_name'] ?? '')) ?: $first;

        $attrs = [
            'sAMAccountName'    => $username,
            'userPrincipalName' => $upn,
            'mail'              => $email,
            'displayName'       => trim($display . ' ' . $last),
            'givenName'         => $first,
            'sn'                => $last,
        ];
        $empId = trim((string) ($p['employee_id'] ?? ''));
        if ($empId !== '') {
            $attrs['employeeID'] = $empId;
        }
        // Title + department mirror OneSync's default mappings. Department is
        // load-bearing downstream — the per-school "Everyone" groups match on it —
        // so it is always the building name (Bus Drivers override to the
        // transportation department).
        // Title drives AD `title` and mirrors into `description`; department drives
        // AD `department` and mirrors into `physicalDeliveryOfficeName` (Office);
        // `info` (Notes) carries the person's Google Workspace email. These mirror
        // OneSync's default mappings so the two agree.
        foreach ($this->desiredMappings($p, $username, $email) as $k => $v) {
            $attrs[$k] = $v;
        }
        // Account expiration = the position end date, when one is set. AD's
        // accountExpires is a Windows FILETIME; midnight UTC of the end date reads
        // back as that date in the verify panel (compareToGolden), so the two agree.
        $expires = self::accountExpiresIso((string) ($p['end_date'] ?? ''));
        if ($expires !== null) {
            $attrs['accountExpires'] = $expires;
        }
        return $attrs;
    }

    /** The person's job title (primary assignment first), '' when none. */
    private function desiredTitle(array $p): string
    {
        return trim((string) ($p['title'] ?? ''));
    }

    /**
     * The AD `department`: the building name — except transportation staff (bus
     * drivers, bus aides, …), whose department is overridden
     * (AD_DEPT_TRANSPORTATION, legacy AD_DEPT_BUS_DRIVER) because they belong to
     * transportation rather than any one school. '' when unresolvable (never
     * blank out AD's value).
     */
    private function desiredDepartment(array $p): string
    {
        if ($this->isTransportation($p)) {
            return trim((string) (Config::get('AD_DEPT_TRANSPORTATION', '') ?: Config::get('AD_DEPT_BUS_DRIVER', 'Transportation')));
        }
        return $this->schoolRow($p)['name'];
    }

    /**
     * The IDM-authoritative operational AD attributes for a person (title,
     * department, and the derived mirrors) — every one non-empty. Shared by create
     * (initial values) and edit (drift target) so both stay identical:
     *
     *   title                        ← job title
     *   description                  ← job title (mirror)
     *   department                   ← building name (transportation staff overridden)
     *   physicalDeliveryOfficeName   ← same as department (Office)
     *   info                         ← the person's Google Workspace email (Notes)
     *
     * $username/$email let create pass the freshly-minted values (the golden record
     * isn't stamped yet); edit omits them and the person row carries username/email.
     *
     * @param array<string,mixed> $p
     * @return array<string,string>
     */
    private function desiredMappings(array $p, ?string $username = null, ?string $email = null): array
    {
        $out = [];
        $title = $this->desiredTitle($p);
        if ($title !== '') {
            $out['title'] = $title;
            $out['description'] = $title;
        }
        $dept = $this->desiredDepartment($p);
        if ($dept !== '') {
            $out['department'] = $dept;
            $out['physicalDeliveryOfficeName'] = $dept;
        }
        $gmail = $this->googleEmail($p, $username, $email);
        if ($gmail !== '') {
            $out['info'] = $gmail;
        }
        return $out;
    }

    /**
     * The person's Google Workspace email (<username>@GOOGLE_DOMAIN via the shared
     * convention). '' when GOOGLE_DOMAIN isn't configured — we only write `info`
     * when we can derive a REAL Google address, never the on-prem golden email. On
     * create the minted username/email are passed explicitly (the row isn't stamped
     * yet).
     *
     * @param array<string,mixed> $p
     */
    private function googleEmail(array $p, ?string $username = null, ?string $email = null): string
    {
        $person = $p;
        if ($username !== null && trim($username) !== '') {
            $person['username'] = $username;
        }
        if ($email !== null && trim($email) !== '') {
            $person['email'] = $email;
        }
        return GoogleWorkspaceService::googleEmailFor($person);
    }

    /**
     * Name-attribute drift for the edit phase: golden first/last (and the derived
     * display name, preferred-name aware like createAttrs) vs live AD
     * givenName/sn/displayName. Pushed IMMEDIATELY on a name change — unlike the
     * username/email/UPN rename, which stays on the RenameService scheduled
     * cutover — so AD reflects a legal name change as soon as the golden record
     * does, matching Google and PowerSchool. Compared exactly (post-trim) so
     * casing corrections propagate; empty golden parts never blank an AD value.
     *
     * @param array<string,mixed>  $p
     * @param array<string,string> $envAttrs normalized (lowercase-keyed) AD attributes
     * @return array<string,string>
     */
    private static function nameDrift(array $p, array $envAttrs): array
    {
        $first = trim((string) ($p['first_name'] ?? ''));
        $last  = trim((string) ($p['last_name'] ?? ''));
        $display = trim((trim((string) ($p['preferred_name'] ?? '')) ?: $first) . ' ' . $last);

        $out = [];
        foreach (['givenName' => $first, 'sn' => $last, 'displayName' => $display] as $attr => $want) {
            if ($want === '') {
                continue; // never blank out AD from an empty golden field
            }
            if ($want !== trim((string) ($envAttrs[strtolower($attr)] ?? ''))) {
                $out[$attr] = $want;
            }
        }
        return $out;
    }

    /**
     * Operational-attribute drift for the edit phase: the desired mappings that are
     * non-empty and differ (case-insensitively) from what live AD holds. Kept in
     * sync because a school move must update department or the per-school
     * Everyone-group logic breaks downstream.
     *
     * @param array<string,mixed>  $p
     * @param array<string,string> $envAttrs normalized (lowercase-keyed) AD attributes
     * @return array<string,string>
     */
    private function operationalDrift(array $p, array $envAttrs): array
    {
        $out = [];
        foreach ($this->desiredMappings($p) as $attr => $want) {
            if ($want === '') {
                continue; // nothing authoritative to push
            }
            // envAttrs are lowercase-keyed (normalizeProperties); match on that.
            $have = trim((string) ($envAttrs[strtolower($attr)] ?? ''));
            if (mb_strtolower($want) !== mb_strtolower($have)) {
                $out[$attr] = $want;
            }
        }
        return $out;
    }

    /**
     * The account's CN (its name/RDN): "First Last" — matching OneSync's rule —
     * made unique when necessary. CN is the RDN, so two same-named people in the
     * same OU would fail the create; on a live-AD cn hit (anywhere — cheaper and
     * stricter than per-OU) fall back to "First Last (username)", which is
     * guaranteed unique because the username is. A search error also falls back —
     * never risk a create we couldn't verify.
     *
     * @param array<string,mixed> $p
     */
    private function uniqueCn(array $p, string $username): string
    {
        $cn = trim(trim((string) ($p['first_name'] ?? '')) . ' ' . trim((string) ($p['last_name'] ?? '')));
        if ($cn === '') {
            return $username;
        }
        $res = $this->read->searchByCriteria([['attr' => 'cn', 'value' => $cn]]);
        if ($res['ok'] && !$res['found']) {
            return $cn;
        }
        return $cn . ' (' . $username . ')';
    }

    /**
     * The full container DN a new account is created in, or null when it cannot
     * be resolved (no school.ad_ou for a placement that needs one). Assembled
     * most-specific first; $baseDn is checked by the caller.
     *
     *   default         : [OU=<type leaf>,] {school.ad_ou} , {AD_PARENT_OU} , {AD_BASE_DN}
     *   transportation  : {AD_OU_TRANSPORTATION} , {AD_PARENT_OU} , {AD_BASE_DN}   (no school OU)
     *   SRO             : {AD_OU_SRO} , {school.ad_ou} , {AD_PARENT_OU} , {AD_BASE_DN}
     *
     * All provisioned accounts nest under a shared parent OU (AD_PARENT_OU,
     * "OU=Faculty" at TCS). Contractors/subs/interns get a type-specific leaf OU
     * as the innermost segment (faculty/staff none); three title-driven rules trump
     * the type leaf: transportation staff (bus drivers, bus aides, …) live in a
     * transportation OU with NO building segment, SROs get an SRO leaf above their
     * building, and substitutes (by title, whatever their person_type) get the Subs
     * leaf above their building. e.g. a contractor at Central Office →
     * OU=PTC,OU=CO,OU=Faculty,<base>; a bus aide → OU=trans,OU=Faculty,<base>; an
     * SRO at BHS → OU=SRO,OU=BHS,OU=Faculty,<base>; a substitute at CO →
     * OU=Subs,OU=CO,OU=Faculty,<base>.
     *
     * @param array<string,mixed> $p
     */
    private function placement(array $p): ?string
    {
        $tail = array_values(array_filter([$this->parentOu, $this->baseDn], static fn($s) => $s !== ''));

        if ($this->isTransportation($p)) {
            $transOu = trim((string) (Config::get('AD_OU_TRANSPORTATION', '') ?: Config::get('AD_OU_BUS_DRIVER', 'OU=trans')), ' ,');
            return implode(',', array_merge([$transOu], $tail));
        }

        $adOu = $this->schoolRow($p)['ad_ou'];
        if ($adOu === '') {
            return null; // every non-bus-driver placement needs the building OU
        }

        if (self::isSro($p)) {
            $sroOu = trim((string) Config::get('AD_OU_SRO', 'OU=SRO'), ' ,');
            return implode(',', array_merge([$sroOu, $adOu], $tail));
        }

        $parts = [];
        // Substitutes are identified by TITLE ("Substitute", "Long-term
        // Substitute"), not just person_type: many arrive through the NextGen /
        // PowerSchool feed as staff/faculty and would otherwise land at the root
        // building OU. Title trumps the type leaf so they get the Subs OU.
        $leaf = self::isSubstitute($p)
            ? $this->typeLeafOu('sub')
            : $this->typeLeafOu((string) ($p['person_type'] ?? ''));
        if ($leaf !== '') {
            $parts[] = $leaf;
        }
        $parts[] = $adOu;
        return implode(',', array_merge($parts, $tail));
    }

    /**
     * The destination container for an OU move, or null when no move is warranted:
     * the account is already in the right OU, the desired placement can't be
     * resolved (no AD_BASE_DN, no school.ad_ou), or AD didn't return a DN to compare.
     * Compares the current DN's parent (everything past the RDN) against placement()
     * case- and whitespace-insensitively so cosmetic DN differences don't churn.
     *
     * @param array<string,mixed>  $p
     * @param array<string,string> $adAttrs normalized (lowercase-keyed) AD attributes
     */
    private function ouMoveTarget(array $p, array $adAttrs): ?string
    {
        if ($this->baseDn === '') {
            return null; // can't form a full container DN to move into
        }
        $desired = $this->placement($p);
        if ($desired === null) {
            return null; // no resolvable placement (e.g. missing school.ad_ou)
        }
        $currentDn = trim((string) ($adAttrs['distinguishedname'] ?? ''));
        if ($currentDn === '') {
            return null; // AD didn't return a DN — nothing to compare against
        }
        $currentParent = self::parentDn($currentDn);
        if ($currentParent === '' || self::dnEquals($currentParent, $desired)) {
            return null; // already in the right container
        }
        return $desired;
    }

    /** A DN's parent container: everything after the first UNescaped comma. '' when none. */
    private static function parentDn(string $dn): string
    {
        $len = strlen($dn);
        for ($i = 0; $i < $len; $i++) {
            if ($dn[$i] === ',' && ($i === 0 || $dn[$i - 1] !== '\\')) {
                return trim(substr($dn, $i + 1));
            }
        }
        return '';
    }

    /** DN equality, ignoring case and the optional spaces around commas/equals. */
    private static function dnEquals(string $a, string $b): bool
    {
        return self::normalizeDn($a) === self::normalizeDn($b);
    }

    private static function normalizeDn(string $dn): string
    {
        $dn = (string) preg_replace('/\s*,\s*/', ',', trim($dn));
        $dn = (string) preg_replace('/\s*=\s*/', '=', $dn);
        return mb_strtolower($dn);
    }

    /**
     * Transportation rule (see placement/desiredDepartment). True when either:
     *  - the TITLE is transportation — Bus Driver/Aide/Monitor/Assistant, … or any
     *    title that names "Transportation" (Transportation Coordinator, Director of
     *    Transportation, …). "bus" is matched as a WHOLE WORD so it covers all of
     *    those without false-matching "Business"; "transportation" is a plain
     *    substring (distinctive enough not to false-match). Extra titles (e.g. a
     *    mechanic or dispatcher) can be added via AD_TRANSPORTATION_TITLES; or
     *  - the person's primary BUILDING is a transportation location, i.e. its
     *    NextGen code is in AD_TRANSPORTATION_SCHOOL_CODES (default 8410) — so
     *    everyone housed at the transportation depot lands in the trans OU no
     *    matter what their individual title says.
     */
    private function isTransportation(array $p): bool
    {
        $title = (string) ($p['title'] ?? '');
        if (preg_match('/\bbus\b/i', $title) || stripos($title, 'transportation') !== false) {
            return true;
        }
        foreach (self::extraTransportationKeywords() as $kw) {
            if (stripos($title, $kw) !== false) {
                return true;
            }
        }
        $schoolId = $p['primary_school_id'] ?? null;
        if ($schoolId !== null && $schoolId !== '' && isset($this->transportationSchoolIds()[(int) $schoolId])) {
            return true;
        }
        return false;
    }

    /**
     * The set of school_ids that are DEDICATED transportation buildings — a school
     * whose NextGen code is in AD_TRANSPORTATION_SCHOOL_CODES (default "8410", the
     * TCS transportation depot) AND which carries no OTHER NextGen code.
     *
     * The "no other code" guard is essential: at TCS the 8410 code is often just an
     * alias on the Central Office row (which also owns 8620 and more), so every
     * building resolves 8410 → Central Office's school_id. Matching on the code
     * alone would then flag every Central Office employee (a Bookkeeper, etc.) as
     * transportation. Requiring the building to be transportation-ONLY means a
     * shared building like Central Office is never treated as transportation;
     * only a building split off with 8410 as its sole NextGen code qualifies (see
     * bin/split_transportation_building.php). Resolved once per run and cached.
     *
     * @return array<int,true>
     */
    private function transportationSchoolIds(): array
    {
        if ($this->transportationSchoolIds !== null) {
            return $this->transportationSchoolIds;
        }
        $codes = array_values(array_filter(
            array_map('trim', explode(',', (string) Config::get('AD_TRANSPORTATION_SCHOOL_CODES', '8410'))),
            static fn($s) => $s !== '',
        ));
        if ($codes === []) {
            return $this->transportationSchoolIds = [];
        }
        $ph = implode(',', array_fill(0, count($codes), '?'));
        // Schools that HAVE a transportation code but do NOT also have a NextGen
        // code outside the transportation set (i.e. not a shared building).
        $stmt = $this->db->prepare(
            "SELECT DISTINCT school_id FROM school_code_alias
              WHERE system = 'nextgen' AND code IN ({$ph})
                AND school_id NOT IN (
                    SELECT school_id FROM school_code_alias
                     WHERE system = 'nextgen' AND code NOT IN ({$ph})
                )"
        );
        $stmt->execute(array_merge($codes, $codes));
        $ids = [];
        foreach ($stmt->fetchAll() as $r) {
            $ids[(int) $r['school_id']] = true;
        }
        return $this->transportationSchoolIds = $ids;
    }

    /** Extra transportation title keywords (substring, case-insensitive). @return list<string> */
    private static function extraTransportationKeywords(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) Config::get('AD_TRANSPORTATION_TITLES', ''))),
            static fn($s) => $s !== '',
        ));
    }

    /** School Resource Officer title rule (see placement). */
    private static function isSro(array $p): bool
    {
        return (bool) preg_match('/\bSRO\b|school\s+resource\s+officer/i', (string) ($p['title'] ?? ''));
    }

    /**
     * Substitute title rule (see placement): "Substitute", "Long-term
     * Substitute", "Substitute Teacher", … — anyone whose job title says
     * substitute belongs in the Subs OU under their building, regardless of the
     * person_type the feed stamped them with. Matched as a whole word so it never
     * false-matches an unrelated title.
     */
    private static function isSubstitute(array $p): bool
    {
        return (bool) preg_match('/\bsubstitutes?\b/i', (string) ($p['title'] ?? ''));
    }

    /**
     * The person's building row (name + relative ad_ou), both '' when the person
     * has no resolvable school. school.ad_ou is a partial path (e.g. "OU=CO" or
     * "OU=STC,OU=CO") — placement() assembles the full DN.
     *
     * @param array<string,mixed> $p
     * @return array{name:string, ad_ou:string}
     */
    private function schoolRow(array $p): array
    {
        $schoolId = $p['primary_school_id'] ?? null;
        if ($schoolId === null || $schoolId === '') {
            return ['name' => '', 'ad_ou' => ''];
        }
        $stmt = $this->db->prepare('SELECT name, ad_ou FROM school WHERE school_id = :id');
        $stmt->execute([':id' => (int) $schoolId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['name' => '', 'ad_ou' => ''];
        }
        return [
            'name'  => trim((string) ($row['name'] ?? '')),
            'ad_ou' => trim((string) ($row['ad_ou'] ?? ''), ' ,'),
        ];
    }

    /**
     * The type-specific leaf OU prepended to the container, by person_type.
     * Defaults follow TCS's layout (contractor→PTC, sub→Subs, intern→Interns;
     * faculty/staff/other have none) and each is overridable via AD_OU_<TYPE>
     * (e.g. AD_OU_CONTRACTOR=OU=Vendors).
     */
    private function typeLeafOu(string $personType): string
    {
        $type = strtolower(trim($personType));
        $override = Config::get('AD_OU_' . strtoupper($type));
        if ($override !== null && trim($override) !== '') {
            return trim($override, " ,");
        }
        return self::DEFAULT_TYPE_LEAF_OU[$type] ?? '';
    }

    /** Default innermost OU per person_type (see typeLeafOu). */
    private const DEFAULT_TYPE_LEAF_OU = [
        'faculty'    => '',
        'staff'      => '',
        'contractor' => 'OU=PTC',
        'sub'        => 'OU=Subs',   // matches OneSync's existing placement (plural)
        'intern'     => 'OU=Interns',
        'other'      => '',
    ];

    /**
     * The accountExpires value to WRITE for a Y-m-d date: midnight UTC of that
     * date as an ISO-8601 timestamp (e.g. "2027-05-31T00:00:00Z"), which is the
     * format the Adaxes REST API expects for a Timestamp property. Returns null
     * for an empty/unparseable date (leave AD's expiry alone). (The read side
     * decodes AD's native FILETIME back to a date — see AdaxesService.)
     */
    private static function accountExpiresIso(string $endDate): ?string
    {
        $endDate = trim($endDate);
        if ($endDate === '') {
            return null;
        }
        $ts = strtotime($endDate . ' 00:00:00 UTC');
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d\T00:00:00\Z', $ts);
    }

    /**
     * The edit delta from a verify() comparison: golden values for the writable,
     * mutable attributes (UPN, mail) that differ or are missing on the AD side and
     * have a non-empty golden value. sAMAccountName is intentionally excluded.
     *
     * @param array<int,array{field:string,label:string,golden:string,ad:string,state:string}> $comparison
     * @return array<string,string>
     */
    private static function editDelta(array $comparison): array
    {
        $writable = ['userPrincipalName' => true, 'mail' => true];
        $attrs = [];
        foreach ($comparison as $row) {
            $field = $row['field'] ?? '';
            if (!isset($writable[$field])) {
                continue;
            }
            if (!in_array($row['state'] ?? '', ['differ', 'missing'], true)) {
                continue;
            }
            $golden = trim((string) ($row['golden'] ?? ''));
            if ($golden === '') {
                continue; // never blank out AD from an empty golden field
            }
            $attrs[$field] = $golden;
        }
        return $attrs;
    }

    /** Well-formed, ACTIVE AD objectGUID from the crosswalk, or null. */
    private static function linkedGuid(array $sourceIds): ?string
    {
        foreach ($sourceIds as $row) {
            if (strtolower((string) ($row['system'] ?? '')) !== 'ad') {
                continue;
            }
            // An inactive AD link (unlinked, or flagged bad) is not a live write
            // target — never act on it.
            if (empty($row['is_active'])) {
                continue;
            }
            $key = trim((string) ($row['source_key'] ?? ''));
            if (preg_match(self::GUID_RE, $key)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $p
     * @return list<array{system:string,source_key:string,is_active:int}>
     */
    private function sourceIdsFor(int $personId): array
    {
        $stmt = $this->db->prepare('SELECT system, source_key, is_active FROM person_source_id WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        return $stmt->fetchAll();
    }

    /** Count of currently-linked, active AD accounts (the disable-ratio denominator). */
    private function countLinkedAdAccounts(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM person_source_id WHERE system = 'ad' AND is_active = 1")->fetchColumn();
    }

    /**
     * People matching a WHERE clause, with the columns the phases need.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchPeople(string $where, ?int $limit): array
    {
        $sql = 'SELECT p.person_id, p.person_type, p.status, p.first_name, p.last_name, p.preferred_name,
                       p.username, p.username_locked, p.email, p.upn, p.employee_id,
                       p.primary_school_id, p.end_date, p.raptor_group_override,
                       (SELECT a.title FROM assignment a WHERE a.person_id = p.person_id
                         ORDER BY a.is_primary DESC, a.id LIMIT 1) AS title
                FROM person p
                WHERE ' . $where;
        // Test-cohort restriction: values are ints (cast in run()), so inlining is safe.
        if ($this->restrictPersonIds !== []) {
            $sql .= ' AND p.person_id IN (' . implode(',', array_map('intval', $this->restrictPersonIds)) . ')';
        }
        $sql .= ' ORDER BY p.person_id';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        return $this->db->query($sql)->fetchAll();
    }

    /** @param array<string,mixed> $p */
    private static function displayName(array $p): string
    {
        return trim((string) ($p['first_name'] ?? '') . ' ' . (string) ($p['last_name'] ?? '')) ?: ('#' . (string) ($p['person_id'] ?? '?'));
    }

    /** @param array<string,string> $attrs */
    private static function attrsSummary(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return implode(', ', $parts);
    }

    /**
     * A "current → proposed" change summary for the dry-run report: for each
     * attribute the edit would push, show what live AD holds now (or "(unset)"
     * when the account has no value) alongside the value IDM would write. This is
     * what makes --dry-run a genuine what's-set-now vs. what-would-change report
     * rather than a list of intended new values. $adAttrs is the normalized
     * (lowercase-keyed) live-AD attribute map, so the "before" side is the
     * account's real current value.
     *
     * @param array<string,string> $attrs   proposed attr => new value
     * @param array<string,string> $adAttrs normalized live-AD attributes
     */
    private static function changeSummary(array $attrs, array $adAttrs): string
    {
        $parts = [];
        foreach ($attrs as $attr => $new) {
            $current = trim((string) ($adAttrs[strtolower($attr)] ?? ''));
            $parts[] = $attr . ': ' . ($current === '' ? '(unset)' : $current) . ' → ' . $new;
        }
        return implode(', ', $parts);
    }

    /**
     * Record one decision: append it to the phase result AND stream it live to the
     * progress callback (if any). Called at the moment each person's outcome is
     * decided, so --verbose shows progress as the run happens rather than in one
     * batch at the end.
     *
     * @param array<string,mixed> $out phase result accumulator (items appended by ref)
     */
    private function item(array &$out, int $pid, string $name, string $action, string $outcome, string $detail): void
    {
        $item = ['person_id' => $pid, 'name' => $name, 'action' => $action, 'outcome' => $outcome, 'detail' => $detail];
        $out['items'][] = $item;
        $this->emit('item', $item);
    }

    /** Fire a progress event on the callback, if one was supplied to run(). */
    private function emit(string $event, array $data): void
    {
        if ($this->log !== null) {
            ($this->log)($event, $data);
        }
    }
}
