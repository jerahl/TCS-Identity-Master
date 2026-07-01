<?php

declare(strict_types=1);

namespace App\Controller;

use App\Db;
use App\Import\FieldMap;
use App\Import\NormalizedRow;
use App\Import\PersonWriter;
use App\Service\AdaxesService;
use App\Service\AuditService;
use App\Service\PersonService;
use App\Support\Csrf;
use App\Config;
use App\Sync\Destinations;
use App\Sync\Freshness;

/**
 * People list + person detail (read), and manual Add person (editor+).
 */
final class PersonController extends Controller
{
    private AdaxesService $adaxes;

    public function __construct(?PersonService $people = null, ?AdaxesService $adaxes = null)
    {
        parent::__construct($people);
        $this->adaxes = $adaxes ?? new AdaxesService();
    }

    public function index(): string
    {
        $filters = [
            'status'  => (string) ($_GET['status'] ?? 'all'),
            'type'    => (string) ($_GET['type'] ?? 'all'),
            'school'  => (string) ($_GET['school'] ?? 'all'),
            'missing' => isset($_GET['missing']) && $_GET['missing'] !== '0',
            'pending' => isset($_GET['pending']) && $_GET['pending'] !== '0',
            'q'       => (string) ($_GET['q'] ?? ''),
            'sort'    => (string) ($_GET['sort'] ?? 'name'),
            'dir'     => strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
        ];

        $result = $this->people->list($filters);

        return $this->render('people/index', [
            'people'        => $result['rows'],
            'shown'         => count($result['rows']),
            'total'         => $result['total'],
            'filters'       => $filters,
            'schoolOptions' => $this->people->schoolFilterOptions(),
        ], 'people', 'People', 'People — TCS Identity Master');
    }

    public function show(array $params): string
    {
        $id = (int) ($params['id'] ?? 0);
        $person = $id > 0 ? $this->people->find($id) : null;

        if ($person === null) {
            http_response_code(404);
            return $this->render('pages/not_found', [
                'message' => 'No person with that id.',
            ], 'people', 'People  /  Not found', 'Not found — TCS Identity Master');
        }

        $assignments = $this->people->assignments($id);
        $sourceIds = $this->people->sourceIds($id);

        // Live AD verification (Adaxes REST). Read-only and config-gated — when
        // ADAXES_* isn't set the service returns configured=false and the panel
        // simply explains how to turn it on. The lookup only fires when enabled.
        $adaxes = $this->adaxes->verify($person, $sourceIds);

        // Backfill from the live match: record the objectGUID and fill the
        // username (locked) / email / UPN where the golden record is empty, so
        // future lookups resolve by GUID and the record reflects the real AD
        // account. Only fires when something is actually missing; idempotent,
        // audited, best-effort (never breaks the page).
        if (!empty($adaxes['found']) && $this->needsAdBackfill($person, $sourceIds, $adaxes)) {
            [$person, $sourceIds] = $this->backfillFromAd($id, $adaxes, $person, $sourceIds);
        }

        // Per-person NextGen↔PowerSchool verification: compare what each system
        // actually staged (assignments come back primary-first, so [0] is primary).
        $src = $this->people->latestSourceValues($id);
        $hasNextGen = $src['nextgen'] !== null;
        $hasPowerSchool = $src['powerschool'] !== null;
        $psStale = !empty($src['powerschool_stale']) && !$hasPowerSchool;
        // IDM-only = neither feed has a usable record (and PS isn't merely stale).
        $idmOnly = !$hasNextGen && !$hasPowerSchool && !$psStale;

        return $this->render('people/show', [
            'p'          => $person,
            'sourceIds'  => $sourceIds,
            'adaxes'     => $adaxes,
            'assignments' => $assignments,
            'syncStatus' => $this->annotateFreshness(Destinations::merge($this->people->syncStatus($id))),
            'timeline'   => $this->people->timeline($id),
            'fieldMap'       => FieldMap::reconcileRows($person, $assignments[0] ?? null, $src['nextgen'], $src['powerschool'], $idmOnly),
            'fieldGroups'    => FieldMap::GROUPS,
            'hasNextGen'     => $hasNextGen,
            'hasPowerSchool' => $hasPowerSchool,
            'psStale'        => $psStale,
            'idmOnly'        => $idmOnly,
        ], 'people', 'People  /  Record', 'Person record — TCS Identity Master');
    }

    /**
     * Is there anything to backfill from the matched AD account — a GUID we don't
     * hold, or an empty username/email on the golden record?
     *
     * @param array<string,mixed> $person
     * @param array<int,array<string,mixed>> $sourceIds
     * @param array<string,mixed> $adaxes
     */
    private function needsAdBackfill(array $person, array $sourceIds, array $adaxes): bool
    {
        $guid = (string) ($adaxes['guid'] ?? '');
        if ($guid !== '') {
            $haveGuid = false;
            foreach ($sourceIds as $s) {
                if (($s['system'] ?? '') === 'ad' && (string) ($s['source_key'] ?? '') === $guid) {
                    $haveGuid = true;
                    break;
                }
            }
            if (!$haveGuid) {
                return true;
            }
        }
        return trim((string) ($person['username'] ?? '')) === ''
            || trim((string) ($person['email'] ?? '')) === '';
    }

    /**
     * Record the matched AD account's objectGUID + fill empty username/email/UPN
     * on the golden record (PersonWriter::linkAdAccount). Idempotent and audited;
     * a write failure is logged and never breaks the page. Returns the refreshed
     * [person, sourceIds].
     *
     * @param array<string,mixed> $person
     * @param array<int,array<string,mixed>> $sourceIds
     * @return array{0:array<string,mixed>, 1:array<int,array<string,mixed>>}
     */
    private function backfillFromAd(int $personId, array $adaxes, array $person, array $sourceIds): array
    {
        try {
            $attrs = $adaxes['attributes'] ?? [];
            $db = Db::connect(Db::ROLE_APP);
            (new PersonWriter($db, new AuditService($db)))->linkAdAccount($personId, [
                'guid'     => $adaxes['guid'] ?? null,
                'username' => $attrs['samaccountname'] ?? null,
                'email'    => $attrs['mail'] ?? null,
                'upn'      => $attrs['userprincipalname'] ?? null,
            ], $this->currentUser()['name']);
            return [$this->people->find($personId) ?? $person, $this->people->sourceIds($personId)];
        } catch (\Throwable $e) {
            error_log('[idm] adaxes backfill: ' . $e->getMessage());
            return [$person, $sourceIds];
        }
    }

    private const PERSON_TYPES = ['faculty', 'staff', 'contractor', 'sub', 'intern', 'other'];

    /** Tag each reported destination with how fresh its last sync is. */
    private function annotateFreshness(array $rows): array
    {
        $staleHours = max(1, (int) Config::get('SYNC_STALE_HOURS', '26'));
        $now = time();
        foreach ($rows as &$r) {
            if (!empty($r['reported'])) {
                $f = Freshness::classify($r['last_sync_at'] ?? null, $staleHours, $now);
                $r['fresh_state'] = $f['state'];
                $r['fresh_label'] = $f['label'];
            }
        }
        return $rows;
    }

    private const STATUSES = ['pending', 'active', 'disabled', 'terminated'];

    /** Edit form for the human-owned fields (editor+). */
    public function editForm(array $params, array $old = [], string $error = ''): string
    {
        $id = (int) ($params['id'] ?? 0);
        $person = $id > 0 ? $this->people->find($id) : null;
        if ($person === null) {
            http_response_code(404);
            return $this->render('pages/not_found', ['message' => 'No person with that id.'], 'people', 'People  /  Not found', 'Not found');
        }

        $values = $old !== [] ? $old : [
            'person_type' => $person['person_type'], 'status' => $person['status'],
            'first_name' => $person['first_name'], 'middle_name' => $person['middle_name'],
            'last_name' => $person['last_name'], 'preferred_name' => $person['preferred_name'],
            'dob' => $person['dob'], 'gender' => $person['gender'],
            'ethnicity_source' => $person['ethnicity_source'], 'alsde_id' => $person['alsde_id'],
            'employee_id' => $person['employee_id'], 'primary_school_id' => $person['primary_school_id'],
            'notes' => $person['notes'],
        ];

        return $this->render('people/edit', [
            'p'       => $person,
            'values'  => $values,
            'schools' => $this->people->allSchools(),
            'error'   => $error,
            'csrf'    => Csrf::token(),
        ], 'people', 'People  /  Edit record', 'Edit person — TCS Identity Master');
    }

    /** Apply an edit to the human-owned fields. */
    public function update(array $params): string
    {
        $id = (int) ($params['id'] ?? 0);
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            return $this->editForm(['id' => $id], $_POST, 'Invalid session token — please retry.');
        }
        if ($this->people->find($id) === null) {
            http_response_code(404);
            return $this->render('pages/not_found', ['message' => 'No person with that id.'], 'people', 'People  /  Not found', 'Not found');
        }

        $first = trim((string) ($_POST['first_name'] ?? ''));
        $last = trim((string) ($_POST['last_name'] ?? ''));
        $type = (string) ($_POST['person_type'] ?? '');
        $status = (string) ($_POST['status'] ?? '');
        $dob = trim((string) ($_POST['dob'] ?? ''));

        if ($first === '' || $last === '') {
            return $this->editForm(['id' => $id], $_POST, 'First and last name are required.');
        }
        if (!in_array($type, self::PERSON_TYPES, true)) {
            return $this->editForm(['id' => $id], $_POST, 'Invalid person type.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            return $this->editForm(['id' => $id], $_POST, 'Invalid status.');
        }
        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            return $this->editForm(['id' => $id], $_POST, 'Date of birth must be YYYY-MM-DD.');
        }

        $ethSource = trim((string) ($_POST['ethnicity_source'] ?? ''));
        $fields = [
            'person_type'      => $type,
            'status'           => $status,
            'first_name'       => $first,
            'middle_name'      => trim((string) ($_POST['middle_name'] ?? '')),
            'last_name'        => $last,
            'preferred_name'   => trim((string) ($_POST['preferred_name'] ?? '')),
            'dob'              => $dob,
            'gender'           => trim((string) ($_POST['gender'] ?? '')),
            'ethnicity_source' => $ethSource,
            'ethnicity_code'   => $ethSource === '' ? '' : ($this->people->ethnicityCodeFor($ethSource) ?? ''),
            'alsde_id'         => trim((string) ($_POST['alsde_id'] ?? '')),
            'employee_id'      => trim((string) ($_POST['employee_id'] ?? '')),
            'primary_school_id' => ($_POST['primary_school_id'] ?? '') !== '' ? (int) $_POST['primary_school_id'] : '',
            'notes'            => trim((string) ($_POST['notes'] ?? '')),
        ];

        try {
            $db = Db::connect(Db::ROLE_APP);
            (new PersonWriter($db, new AuditService($db)))->updateProfile($id, $fields, $this->currentUser()['name']);
            $this->flash('Record saved — change written to the audit log.');
            return $this->redirect(url('/people/' . $id));
        } catch (\Throwable $e) {
            error_log('[idm] person update: ' . $e->getMessage());
            return $this->editForm(['id' => $id], $_POST, 'Could not save the record.');
        }
    }

    /**
     * Approve the disable of a person flagged on the dashboard "Not in NextGen —
     * review to disable" panel. Sets status = 'disabled' (audited + a 'disable'
     * lifecycle event via updateProfile), so OneSync disables — not orphans — the
     * account on its next read of v_onesync_source. Editor+; CSRF-checked; a POST
     * form (no inline JS, CSP-safe). Idempotent-ish: a no-op if already
     * disabled/terminated. Always redirects back to the dashboard panel.
     */
    public function disable(array $params): string
    {
        $id = (int) ($params['id'] ?? 0);
        $back = url('/dashboard') . '#disable';

        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect($back);
        }
        $person = $id > 0 ? $this->people->find($id) : null;
        if ($person === null) {
            $this->flash('That person no longer exists.');
            return $this->redirect($back);
        }
        $name = trim((string) $person['first_name'] . ' ' . (string) $person['last_name']);
        if (in_array((string) $person['status'], ['disabled', 'terminated'], true)) {
            $this->flash("{$name} is already {$person['status']} — no change.");
            return $this->redirect($back);
        }

        try {
            $db = Db::connect(Db::ROLE_APP);
            (new PersonWriter($db, new AuditService($db)))
                ->updateProfile($id, ['status' => 'disabled'], $this->currentUser()['name']);
            $this->flash("{$name} disabled — OneSync will disable the account on its next read.");
        } catch (\Throwable $e) {
            error_log('[idm] person disable: ' . $e->getMessage());
            $this->flash('Could not disable the record.');
        }
        return $this->redirect($back);
    }

    /** Manual add form (for subs/contractors/interns not in HR). */
    public function addForm(array $old = [], string $error = ''): string
    {
        // Pre-select a type when arriving via /add?type=intern (etc.).
        if ($old === [] && isset($_GET['type']) && in_array($_GET['type'], self::PERSON_TYPES, true)) {
            $old['person_type'] = (string) $_GET['type'];
        }
        return $this->render('people/add', [
            'schools' => $this->people->allSchools(),
            'old'     => $old,
            'error'   => $error,
            'csrf'    => Csrf::token(),
        ], 'add', 'People  /  Add person', 'Add person — TCS Identity Master');
    }

    /** Create a manual pending person. */
    public function create(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            return $this->addForm($_POST, 'Invalid session token — please retry.');
        }

        $first = trim((string) ($_POST['first_name'] ?? ''));
        $last = trim((string) ($_POST['last_name'] ?? ''));
        $type = (string) ($_POST['person_type'] ?? 'sub');

        if ($first === '' || $last === '') {
            return $this->addForm($_POST, 'First and last name are required.');
        }
        if (!in_array($type, self::PERSON_TYPES, true)) {
            return $this->addForm($_POST, 'Invalid person type.');
        }
        $dob = trim((string) ($_POST['dob'] ?? ''));
        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            return $this->addForm($_POST, 'Date of birth must be YYYY-MM-DD.');
        }
        $schoolId = ($_POST['school_id'] ?? '') !== '' ? (int) $_POST['school_id'] : null;

        $row = new NormalizedRow(
            system: 'manual',
            sourceKey: '',
            firstName: $first,
            lastName: $last,
            middleName: trim((string) ($_POST['middle_name'] ?? '')) ?: null,
            preferredName: trim((string) ($_POST['preferred_name'] ?? '')) ?: null,
            dob: $dob ?: null,
            gender: trim((string) ($_POST['gender'] ?? '')) ?: null,
            schoolId: $schoolId,
            personType: $type,
            title: trim((string) ($_POST['title'] ?? '')) ?: null,
            fte: trim((string) ($_POST['fte'] ?? '')) ?: null,
            isPrimary: true,
        );

        try {
            $db = Db::connect(Db::ROLE_APP);
            $writer = new PersonWriter($db, new AuditService($db));
            $actor = $this->currentUser()['name'];

            $db->beginTransaction();
            $pid = $writer->createPerson($row, $actor);
            $writer->attachSourceId($pid, 'manual', (string) $pid, $actor);
            $writer->upsertAssignment($pid, $row, $actor);
            $db->commit();

            $this->flash("{$first} {$last} created — pending activation. OneSync will mint the username.");
            return $this->redirect(url('/people/' . $pid));
        } catch (\Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[idm] manual add: ' . $e->getMessage());
            return $this->addForm($_POST, 'Could not create the record.');
        }
    }
}
