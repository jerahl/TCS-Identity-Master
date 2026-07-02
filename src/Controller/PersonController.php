<?php

declare(strict_types=1);

namespace App\Controller;

use App\Db;
use App\Import\FieldMap;
use App\Import\NormalizedRow;
use App\Import\Normalizer;
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
        //
        // NOTE: this is display-only. Viewing a record must never mutate it — a
        // GET that writes (activates a pending person, backfills the username)
        // makes the list and detail views disagree and lets a mere page view
        // change data. Linking the AD account + activating a provisioned person
        // is the batch AdUsernameImporter's job (and OneSync write-back), not the
        // detail page's.
        //
        // The lookup is a live REST round trip that can be slow, so we don't run
        // it here — the page renders immediately with a loading indicator and the
        // panel is fetched over AJAX from adaxes() below. Only when Adaxes isn't
        // configured (nothing to look up, no HTTP) do we resolve the off-state
        // envelope inline so the template can render it without a round trip.
        $adaxesConfigured = $this->adaxes->configured();
        $adaxes = $adaxesConfigured ? null : $this->adaxes->verify($person, $sourceIds);

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
            'adaxesConfigured' => $adaxesConfigured,
            'adaxesUrl'  => url('/people/' . $id . '/adaxes'),
            'assignments' => $assignments,
            'syncStatus' => $this->annotateFreshness(Destinations::merge($this->people->syncStatus($id))),
            'timeline'   => $this->people->timeline($id),
            'fieldMap'       => FieldMap::reconcileRows($person, $assignments[0] ?? null, $src['nextgen'], $src['powerschool'], $idmOnly),
            'fieldGroups'    => FieldMap::GROUPS,
            'hasNextGen'     => $hasNextGen,
            'hasPowerSchool' => $hasPowerSchool,
            'psStale'        => $psStale,
            'idmOnly'        => $idmOnly,
            'csrf'           => Csrf::token(),
        ], 'people', 'People  /  Record', 'Person record — TCS Identity Master');
    }

    /**
     * AJAX fragment: the live Active Directory verification panel for a person,
     * fetched by public/assets/js/person-adaxes.js after the detail page renders
     * so the (potentially slow) Adaxes REST call never blocks the page load.
     *
     * Returns just the panel's inner HTML (no layout) for insertion into the
     * #adaxes-live placeholder. Same read-only, config-gated, never-mutating
     * contract as show(); 404s a missing person so the client shows an error.
     */
    public function adaxes(array $params): string
    {
        $id = (int) ($params['id'] ?? 0);
        $person = $id > 0 ? $this->people->find($id) : null;
        if ($person === null) {
            http_response_code(404);
            return '';
        }

        $adaxes = $this->adaxes->verify($person, $this->people->sourceIds($id));

        return \App\View\View::partial('people/_adaxes', ['adaxes' => $adaxes]);
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
            'board_approval_date' => $person['board_approval_date'], 'board_approval_note' => $person['board_approval_note'],
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
        $boardApproval = trim((string) ($_POST['board_approval_date'] ?? ''));
        if ($boardApproval !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $boardApproval)) {
            return $this->editForm(['id' => $id], $_POST, 'Board approval date must be YYYY-MM-DD.');
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
            'board_approval_date' => $boardApproval,
            'board_approval_note' => trim((string) ($_POST['board_approval_note'] ?? '')),
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
        $back = url('/review') . '#disable';

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

    /**
     * Apply an operator's source pick from the "Source field reconciliation"
     * panel: write one chosen value (NextGen or PowerSchool) to the golden
     * record — or to the primary assignment, for the fields that live there
     * (title). Editor+; CSRF-checked; a POST form (no inline JS, CSP-safe).
     *
     * The value written is read from the SAME reconcile rows the panel rendered
     * (rebuilt here from the staged feeds), so what lands on the record is
     * exactly what the operator saw beside the button — never a stale re-derived
     * value. Only fields the panel marks overridable are accepted; the writers
     * whitelist columns and skip no-op writes, so a redundant pick is quiet.
     * Always redirects back to the person page.
     */
    public function reconcile(array $params): string
    {
        $id = (int) ($params['id'] ?? 0);
        $back = url('/people/' . $id);

        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect($back);
        }
        $person = $id > 0 ? $this->people->find($id) : null;
        if ($person === null) {
            $this->flash('That person no longer exists.');
            return $this->redirect($back);
        }

        $fieldKey = (string) ($_POST['field'] ?? '');
        $source   = (string) ($_POST['source'] ?? '');
        if (!in_array($source, ['nextgen', 'powerschool'], true)) {
            $this->flash('Unknown source — nothing written.');
            return $this->redirect($back);
        }

        // Rebuild the reconcile rows exactly as show() does, so the value we
        // write matches what was displayed beside the "Use this" button.
        $assignments = $this->people->assignments($id);
        $primary = $assignments[0] ?? null;
        $src = $this->people->latestSourceValues($id);
        $hasNextGen = $src['nextgen'] !== null;
        $hasPowerSchool = $src['powerschool'] !== null;
        $psStale = !empty($src['powerschool_stale']) && !$hasPowerSchool;
        $idmOnly = !$hasNextGen && !$hasPowerSchool && !$psStale;
        $rows = FieldMap::reconcileRows($person, $primary, $src['nextgen'], $src['powerschool'], $idmOnly);

        $row = null;
        foreach ($rows as $r) {
            if ($r['key'] === $fieldKey) {
                $row = $r;
                break;
            }
        }
        if ($row === null || empty($row['overridable'])) {
            $this->flash('That field can’t be reconciled here.');
            return $this->redirect($back);
        }

        $value = $source === 'nextgen' ? (string) $row['ngValue'] : (string) $row['psValue'];
        if ($value === '') {
            $this->flash('That source has no value for this field — nothing written.');
            return $this->redirect($back);
        }
        if (FieldMap::isDateField($fieldKey)) {
            $value = Normalizer::parseDate($value) ?? $value;
        }

        $sourceLabel = $source === 'powerschool' ? 'PowerSchool' : ($idmOnly ? 'IDM (current)' : 'NextGen');
        $summary = "Reconciled {$row['label']} to the {$sourceLabel} value";

        try {
            $db = Db::connect(Db::ROLE_APP);
            $writer = new PersonWriter($db, new AuditService($db));
            $actor = $this->currentUser()['name'];

            $goldenCol = FieldMap::goldenColumn($fieldKey);
            $assignmentCol = FieldMap::assignmentColumn($fieldKey);

            if ($goldenCol !== null) {
                $values = [$goldenCol => $value];
                // Ethnicity carries a derived ALSDE code — keep the two in step.
                if ($goldenCol === 'ethnicity_source') {
                    $values['ethnicity_code'] = $this->people->ethnicityCodeFor($value);
                }
                $changed = $writer->setGoldenFields($id, $values, $actor, $summary);
            } elseif ($assignmentCol !== null) {
                if ($primary === null) {
                    $this->flash('No primary assignment to update.');
                    return $this->redirect($back);
                }
                $changed = $writer->setAssignmentField($id, (int) $primary['id'], $assignmentCol, $value, $actor, $summary);
            } else {
                $this->flash('That field isn’t writable.');
                return $this->redirect($back);
            }

            $this->flash($changed
                ? "{$row['label']} set to the {$sourceLabel} value — written to the golden record."
                : "{$row['label']} already matched the {$sourceLabel} value — no change.");
        } catch (\Throwable $e) {
            error_log('[idm] person reconcile: ' . $e->getMessage());
            $this->flash('Could not apply the reconciliation.');
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
