<?php

declare(strict_types=1);

namespace App\Import;

/**
 * The canonical field-level crosswalk between the NextGen HR export, the golden
 * record, and PowerSchool — for one human. It answers two questions in one place:
 *
 *   1. "Which NextGen column maps to which PowerSchool field?"  (documentation)
 *   2. "What does this person's value look like, field by field?" (person panel)
 *
 * NextGen is the HR source of record for almost every field; PowerSchool
 * contributes the two demographics NextGen doesn't carry — Date of Birth and the
 * Alabama State ID (ALSID) — which are pulled over ODBC and stored on the record.
 *
 * Each entry declares:
 *   - key         logical id
 *   - label       human label for the UI
 *   - group       UI grouping (identity | position | demographics | contact)
 *   - nextgen     the NextGen ITExtract header (null = not in NextGen)
 *   - powerschool the PowerSchool field reference (null = no PS equivalent)
 *   - golden      where the value lives on the golden record (person.* /
 *                 assignment.*), or null for OneSync-owned fields
 *   - origin      'nextgen' | 'powerschool' — which system the stored value
 *                 comes from (drives where the person panel reads the value)
 *   - pii         true for sensitive PII (phone / home address / DOB)
 *
 * The PowerSchool column names mirror this district's pull (TEACHERS / USERS +
 * the Alabama S_AL_USR_X extension). Adjust them to match the live PS schema —
 * the same "adjust to your schema" caveat as PowerSchoolOdbcReader.
 */
final class FieldMap
{
    /** @var array<int,array{key:string,label:string,group:string,nextgen:?string,powerschool:?string,golden:?string,origin:string,pii:bool}> */
    private const FIELDS = [
        // --- Identity ---------------------------------------------------------
        ['key' => 'employee_id', 'label' => 'Employee Number', 'group' => 'identity',
         'nextgen' => 'Employee Number', 'powerschool' => 'TEACHERS.TeacherNumber', 'golden' => 'person.employee_id', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'last_name', 'label' => 'Last Name', 'group' => 'identity',
         'nextgen' => 'Last Name', 'powerschool' => 'TEACHERS.Last_Name', 'golden' => 'person.last_name', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'first_name', 'label' => 'First Name', 'group' => 'identity',
         'nextgen' => 'First Name', 'powerschool' => 'TEACHERS.First_Name', 'golden' => 'person.first_name', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'hr_email', 'label' => 'EMail Address', 'group' => 'identity',
         'nextgen' => 'EMail Address', 'powerschool' => 'USERS.Email_Addr', 'golden' => 'person.hr_email', 'origin' => 'nextgen', 'pii' => false],

        // --- Position / assignment -------------------------------------------
        ['key' => 'position_number', 'label' => 'Position Number', 'group' => 'position',
         'nextgen' => 'Position Number', 'powerschool' => null, 'golden' => 'person.position_number', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'school_code', 'label' => 'Location Code', 'group' => 'position',
         'nextgen' => 'Location Code', 'powerschool' => 'TEACHERS.SchoolID', 'golden' => 'person.primary_school_id', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'cctr_description', 'label' => 'CCTR Description', 'group' => 'position',
         'nextgen' => 'CCTR Description', 'powerschool' => null, 'golden' => 'person.cctr_description', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'job_code', 'label' => 'JOB CODE', 'group' => 'position',
         'nextgen' => 'JOB CODE', 'powerschool' => null, 'golden' => 'assignment.job_code', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'title', 'label' => 'Job Code Desc', 'group' => 'position',
         'nextgen' => 'Job Code Desc', 'powerschool' => 'TEACHERS.Title', 'golden' => 'assignment.title', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'hire_date', 'label' => 'Hire Date', 'group' => 'position',
         'nextgen' => 'Hire Date', 'powerschool' => 'S_USR_X.hiredate', 'golden' => 'person.hire_date', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'position_start_date', 'label' => 'Position Start Date', 'group' => 'position',
         'nextgen' => 'Position Start Date', 'powerschool' => null, 'golden' => 'person.position_start_date', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'end_date', 'label' => 'Position End Date', 'group' => 'position',
         'nextgen' => 'Position End Date', 'powerschool' => 'S_AL_USR_X.exit_date', 'golden' => 'person.end_date', 'origin' => 'nextgen', 'pii' => false],

        // --- Demographics -----------------------------------------------------
        ['key' => 'ethnicity', 'label' => 'Ethnicity Description', 'group' => 'demographics',
         'nextgen' => 'Ethnicity Description', 'powerschool' => 'S_AL_USR_X (ALSDE code)', 'golden' => 'person.ethnicity_source', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'gender', 'label' => 'Gender Type', 'group' => 'demographics',
         'nextgen' => 'Gender Type', 'powerschool' => 'USERS.Gender', 'golden' => 'person.gender', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'dob', 'label' => 'Date of Birth', 'group' => 'demographics',
         'nextgen' => null, 'powerschool' => 'S_AL_USR_X.dob', 'golden' => 'person.dob', 'origin' => 'powerschool', 'pii' => true],
        ['key' => 'alsde_id', 'label' => 'ALSID', 'group' => 'demographics',
         'nextgen' => null, 'powerschool' => 'S_AL_USR_X.StaffStateID', 'golden' => 'person.alsde_id', 'origin' => 'powerschool', 'pii' => false],

        // --- Contact (PII) ----------------------------------------------------
        ['key' => 'phone', 'label' => 'Phone Number', 'group' => 'contact',
         'nextgen' => 'Phone Number', 'powerschool' => 'USERS.Home_Phone', 'golden' => 'person.phone', 'origin' => 'nextgen', 'pii' => true],
        ['key' => 'address1', 'label' => 'Address 1', 'group' => 'contact',
         'nextgen' => 'Address 1', 'powerschool' => 'USERS.Street', 'golden' => 'person.address1', 'origin' => 'nextgen', 'pii' => true],
        ['key' => 'address2', 'label' => 'Address 2', 'group' => 'contact',
         'nextgen' => 'Address 2', 'powerschool' => null, 'golden' => 'person.address2', 'origin' => 'nextgen', 'pii' => true],
        ['key' => 'city', 'label' => 'City', 'group' => 'contact',
         'nextgen' => 'City', 'powerschool' => 'USERS.City', 'golden' => 'person.city', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'state_code', 'label' => 'State Code', 'group' => 'contact',
         'nextgen' => 'State Code', 'powerschool' => 'USERS.State', 'golden' => 'person.state_code', 'origin' => 'nextgen', 'pii' => false],
        ['key' => 'zip_code', 'label' => 'Zip Code', 'group' => 'contact',
         'nextgen' => 'Zip Code', 'powerschool' => 'USERS.Zip', 'golden' => 'person.zip_code', 'origin' => 'nextgen', 'pii' => false],
    ];

    /** Group labels, in display order. */
    public const GROUPS = [
        'identity'     => 'Identity',
        'position'     => 'Position & assignment',
        'demographics' => 'Demographics',
        'contact'      => 'Contact',
    ];

    /** @return array<int,array{key:string,label:string,group:string,nextgen:?string,powerschool:?string,golden:?string,origin:string,pii:bool}> */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /**
     * Build the per-person mapping rows for the detail panel: each field's NextGen
     * header, PowerSchool field, and the person's resolved value (read from the
     * golden record / primary assignment). `value` is a display string; missing
     * values come back as '' so the view can render a dash.
     *
     * @param array<string,mixed> $person      a `person` row (+ primary_school_name)
     * @param array<string,mixed>|null $primary the person's primary assignment row
     * @return array<int,array{key:string,label:string,group:string,nextgen:?string,powerschool:?string,value:string,pii:bool,origin:string}>
     */
    public static function personRows(array $person, ?array $primary = null): array
    {
        $rows = [];
        foreach (self::FIELDS as $f) {
            $rows[] = [
                'key'         => $f['key'],
                'label'       => $f['label'],
                'group'       => $f['group'],
                'nextgen'     => $f['nextgen'],
                'powerschool' => $f['powerschool'],
                'origin'      => $f['origin'],
                'pii'         => $f['pii'],
                'value'       => self::valueFor($f, $person, $primary),
            ];
        }
        return $rows;
    }

    /** Fields whose values are dates — normalized before comparison. */
    private const DATE_KEYS = ['hire_date', 'position_start_date', 'end_date', 'dob'];

    /**
     * Build the per-person verification rows: each field's NextGen value beside
     * its PowerSchool value, with a reconciliation verdict. This is the heart of
     * the "do the two systems agree?" check — NextGen drives provisioning, and
     * PowerSchool is pulled to confirm it matches.
     *
     * Values come from what each system actually staged (not the merged golden
     * record): $ngRaw is the raw NextGen feed row (keyed by CSV header), $psFields
     * is the PowerSchool snapshot (keyed by field key). A null side means that
     * source has no staged row for this person. When neither feed exists — an
     * IDM-only intern/contractor — pass $idmOnly=true and the NextGen column falls
     * back to the current golden-record value so the panel still shows the record.
     *
     * @param array<string,mixed> $person       golden record row (+ primary_school_name)
     * @param array<string,mixed>|null $primary  primary assignment row
     * @param array<string,mixed>|null $ngRaw    raw NextGen feed row
     * @param array<string,mixed>|null $psFields PowerSchool field snapshot
     * @return array<int,array{key:string,label:string,group:string,nextgen:?string,powerschool:?string,pii:bool,ngValue:string,psValue:string,state:string}>
     */
    public static function reconcileRows(array $person, ?array $primary, ?array $ngRaw, ?array $psFields, bool $idmOnly = false): array
    {
        $hasNg = $ngRaw !== null;
        $hasPs = $psFields !== null;

        $rows = [];
        foreach (self::FIELDS as $f) {
            $ngValue = self::ngValueFor($f, $person, $primary, $ngRaw, $idmOnly);
            $psValue = ($f['powerschool'] !== null && is_array($psFields))
                ? trim((string) ($psFields[$f['key']] ?? ''))
                : '';
            // DOB / ALSID have no NextGen column — PowerSchool is their source and
            // they're stored on the golden record, so show that value even when the
            // staged snapshot is unavailable.
            if ($psValue === '' && $f['nextgen'] === null && $f['powerschool'] !== null) {
                $psValue = self::valueFor($f, $person, $primary);
            }
            $rows[] = [
                'key'         => $f['key'],
                'label'       => $f['label'],
                'group'       => $f['group'],
                'nextgen'     => $f['nextgen'],
                'powerschool' => $f['powerschool'],
                'pii'         => $f['pii'],
                'ngValue'     => $ngValue,
                'psValue'     => $psValue,
                'state'       => self::reconcileState($f, $ngValue, $psValue, $hasNg, $hasPs),
            ];
        }
        return $rows;
    }

    /**
     * NextGen-side value: the raw feed value when NextGen staged a row, else the
     * golden-record value for an IDM-only record, else blank.
     *
     * @param array{key:string,nextgen:?string,golden:?string} $f
     */
    private static function ngValueFor(array $f, array $person, ?array $primary, ?array $ngRaw, bool $idmOnly): string
    {
        if (is_array($ngRaw)) {
            return $f['nextgen'] === null ? '' : trim((string) ($ngRaw[$f['nextgen']] ?? ''));
        }
        return $idmOnly ? self::valueFor($f, $person, $primary) : '';
    }

    /**
     * The reconciliation verdict for one field:
     *   match    — both feeds agree
     *   differ   — both present, values disagree
     *   missing  — one feed has it, the other is blank
     *   ng_only  — field exists only in NextGen (no PowerSchool counterpart)
     *   ps_only  — field exists only in PowerSchool (DOB / ALSID)
     *   info     — school code (different code spaces, not directly comparable)
     *   ''       — can't verify (a feed is absent)
     *
     * @param array{key:string,nextgen:?string,powerschool:?string} $f
     */
    private static function reconcileState(array $f, string $ng, string $ps, bool $hasNg, bool $hasPs): string
    {
        if ($f['key'] === 'school_code') {
            return 'info';
        }
        if ($f['nextgen'] === null) {
            return 'ps_only';
        }
        if ($f['powerschool'] === null) {
            return 'ng_only';
        }
        if (!$hasNg || !$hasPs) {
            return '';
        }
        if ($ng === '' && $ps === '') {
            return '';
        }
        if ($ng === '' || $ps === '') {
            return 'missing';
        }
        return self::valuesEqual($f['key'], $ng, $ps) ? 'match' : 'differ';
    }

    /** Normalized equality: dates by parsed value, phone by digits, else case-insensitive. */
    private static function valuesEqual(string $key, string $a, string $b): bool
    {
        if (in_array($key, self::DATE_KEYS, true)) {
            return Normalizer::parseDate($a) === Normalizer::parseDate($b);
        }
        if ($key === 'phone') {
            return preg_replace('/\D+/', '', $a) === preg_replace('/\D+/', '', $b);
        }
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /**
     * Resolve a single field's display value from the golden record / assignment.
     *
     * @param array{key:string,golden:?string} $f
     * @param array<string,mixed> $person
     * @param array<string,mixed>|null $primary
     */
    private static function valueFor(array $f, array $person, ?array $primary): string
    {
        // Location Code resolves to the primary school's name when known.
        if ($f['key'] === 'school_code') {
            return trim((string) ($person['primary_school_name'] ?? '')) !== ''
                ? (string) $person['primary_school_name']
                : (string) ($person['primary_school_id'] ?? '');
        }

        $golden = $f['golden'];
        if ($golden === null) {
            return '';
        }
        [$table, $col] = array_pad(explode('.', $golden, 2), 2, '');
        $src = $table === 'assignment' ? ($primary ?? []) : $person;

        return trim((string) ($src[$col] ?? ''));
    }
}
