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
 *  2. Create only when truly new — no linked GUID AND no AD search hit.
 *  3. Username immutability — a username_locked person is never re-minted; in the
 *     create phase a locked-but-unlinked person is routed to review (correlate
 *     their existing account) rather than minted a second identity.
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

    /** Optional live-progress callback: fn(string $event, array $data): void. */
    private $log = null;

    /** When non-empty, every phase is restricted to these person_ids (test cohort). */
    private array $restrictPersonIds = [];

    /** Per-run cache: group cn (lowercased) → resolved DN/GUID, or '' if not found. */
    private array $groupIdCache = [];

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
                'accountExpires' => (string) self::accountExpiresFileTime($desiredDate),
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
     * Push golden→AD attribute drift (UPN, mail) for active/pending people with a
     * linked objectGUID. Never touches sAMAccountName (immutable) and never blanks
     * an AD value from an empty golden field.
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
        $out = ['applied' => 0, 'skipped' => 0, 'review' => 0, 'errors' => 0, 'capped' => 0, 'items' => []];

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

            // Immutability: a person who already carries a locked username but has
            // no AD link is anomalous (an existing identity with no linked account).
            // Never mint/create a second account for them — that is a correlation
            // task. Route to review.
            if (!empty($p['username_locked'])) {
                $out['review']++;
                $this->item($out, $pid, $name, 'create', 'review', 'locked username but no AD link — correlate the existing account, do not create');
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

            // Confirm net-new: an AD search must return nothing. A hit means the
            // account exists but isn't linked → correlation task, not a create.
            $search = $this->read->search($p);
            if (!$search['ok']) {
                $out['errors']++;
                $this->item($out, $pid, $name, 'create', 'error', (string) $search['error']);
                continue;
            }
            if ($search['found']) {
                $out['review']++;
                $this->item($out, $pid, $name, 'create', 'review', 'AD account already exists but is unlinked — correlate, do not create');
                continue;
            }

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
        }

        return $out;
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
            $desiredByCn = [];
            foreach ($desired as $cn) {
                $desiredByCn[strtolower($cn)] = $cn;
            }

            // Add: desired groups the account isn't in yet.
            $toAdd = [];
            foreach ($desiredByCn as $lc => $cn) {
                if (!isset($liveByCn[$lc])) {
                    $toAdd[] = $cn;
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

            $summary = trim(($toAdd !== [] ? '+' . implode(',+', $toAdd) : '') . ' ' . ($toRemove !== [] ? '-' . implode(',-', array_column($toRemove, 'cn')) : ''));

            if (!$apply) {
                $this->item($out, $pid, $name, 'groups', 'would-sync', $summary);
                continue;
            }

            $changed = 0;
            $errs = [];
            foreach ($toAdd as $cn) {
                // The API needs the group's DN/GUID, not its name — resolve it.
                $groupId = $this->resolveGroupId($cn);
                if ($groupId === null) {
                    $errs[] = "add {$cn}: group not found in AD (cannot resolve to a DN)";
                    continue;
                }
                $r = $this->writer->addToGroup($groupId, $guid);
                if ($r['ok']) {
                    $out['added']++;
                    $changed++;
                } else {
                    $errs[] = "add {$cn}: " . $r['error'];
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
            $this->audit->log('person', $pid, 'update', ['ad_groups' => 'drift'], ['added' => $toAdd, 'removed' => array_column($toRemove, 'cn')], self::ACTOR);
            $this->audit->lifecycle($pid, 'update', ['summary' => 'AD group membership synced via Adaxes: ' . $summary . '.'], self::ACTOR);
            $out['applied']++;
            $this->item($out, $pid, $name, 'groups', 'synced', $summary);
        }

        return $out;
    }

    /**
     * Resolve a group name to the directory identifier the group-member API needs
     * (its DN, else objectGUID), cached for the run. Null when the group can't be
     * found in AD (so the add is reported as an error rather than a 400).
     */
    private function resolveGroupId(string $cn): ?string
    {
        $key = strtolower(trim($cn));
        if (array_key_exists($key, $this->groupIdCache)) {
            $id = $this->groupIdCache[$key];
            return $id === '' ? null : $id;
        }
        $res = $this->read->findGroup($cn);
        $id = ($res['ok'] && $res['found']) ? (string) $res['id'] : '';
        $this->groupIdCache[$key] = $id;
        return $id === '' ? null : $id;
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
     * Everything operational (home dir, groups, licensing, password) is left to
     * Adaxes Business Rules that fire on the create.
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
        $expires = self::accountExpiresFileTime((string) ($p['end_date'] ?? ''));
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
     * The set of school_ids that are transportation locations — buildings whose
     * NextGen code is listed in AD_TRANSPORTATION_SCHOOL_CODES (default "8410", the
     * TCS transportation depot). Resolved once per run via school_code_alias and
     * cached. Keyed by school_id for O(1) membership tests.
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
        $stmt = $this->db->prepare(
            "SELECT DISTINCT school_id FROM school_code_alias WHERE system = 'nextgen' AND code IN ({$ph})"
        );
        $stmt->execute($codes);
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
     * The Windows FILETIME (100-ns ticks since 1601-01-01 UTC) for midnight UTC of
     * a Y-m-d date, as a string — the inverse of AdaxesService's accountExpires
     * decode. Returns null for an empty/unparseable date (leave AD's expiry alone).
     */
    private static function accountExpiresFileTime(string $endDate): ?string
    {
        $endDate = trim($endDate);
        if ($endDate === '') {
            return null;
        }
        $ts = strtotime($endDate . ' 00:00:00 UTC');
        if ($ts === false) {
            return null;
        }
        return (string) (($ts + 11644473600) * 10000000);
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
