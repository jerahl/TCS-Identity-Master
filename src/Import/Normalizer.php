<?php

declare(strict_types=1);

namespace App\Import;

use App\Db;
use PDO;

/**
 * Turns a raw CSV row (+ column map) into a NormalizedRow: trims fields, parses
 * dates to Y-m-d, and resolves reference values via the maps —
 *   school name  -> school_id   (school.name, strict — an unmatched name errors)
 *   school code  -> school_id   (school_code_alias, per system)
 *   ethnicity    -> ALSDE code  (ethnicity_map)
 *   job code     -> person_type (position_type_map — classifies faculty vs staff)
 * A feed that carries a school *name* column resolves it by name (see the note on
 * the resolution order in normalize()); feeds that only carry a numeric code fall
 * back to the code alias. Unresolved codes/ethnicities are kept raw and recorded
 * as warnings (surfaced on the staged row) rather than silently dropped.
 *
 * Maps are injected as plain arrays so this is unit-testable without a DB;
 * fromDb() builds them from the reference tables.
 */
final class Normalizer
{
    /** @var array<string,array<string,int>> [system][code] => school_id */
    private readonly array $schoolAlias;
    /** @var array<string,string> lower(source_value) => alsde_code */
    private readonly array $ethnicityMap;
    /** Same as $schoolAlias but keyed by zero-stripped code, for padding-tolerant lookup. */
    private readonly array $schoolAliasNorm;
    /** @var array<string,int> normalizeSchoolName(name) => school_id */
    private readonly array $schoolNameIndex;
    /** @var array<string,string> lower(job_code) => person_type */
    private readonly array $positionTypeMap;

    /**
     * @param array<string,array<string,int>> $schoolAlias  [system][code] => school_id
     * @param array<string,string> $ethnicityMap            lower(source_value) => alsde_code
     * @param array<string,int> $schoolNames                school name => school_id (any casing/punctuation)
     * @param array<string,string> $positionTypeMap         job_code => person_type (any casing)
     */
    public function __construct(array $schoolAlias, array $ethnicityMap, array $schoolNames = [], array $positionTypeMap = [])
    {
        $this->schoolAlias = $schoolAlias;
        $this->ethnicityMap = $ethnicityMap;

        // Job codes are matched case-insensitively (feeds are inconsistent about
        // casing). The map may be partial by design — unmapped codes fall through
        // to the source default ('staff' on create), so a district can list only
        // its faculty codes.
        $positions = [];
        foreach ($positionTypeMap as $code => $type) {
            $positions[mb_strtolower(trim((string) $code), 'UTF-8')] ??= (string) $type;
        }
        $this->positionTypeMap = $positions;

        // Normalize school names into a case-/punctuation-insensitive index so a
        // feed value like "Martin Luther King, Jr. Elementary" resolves to the
        // reference "Martin Luther King Jr Elementary School". First one wins.
        $names = [];
        foreach ($schoolNames as $name => $id) {
            $names[self::normalizeSchoolName((string) $name)] ??= (int) $id;
        }
        $this->schoolNameIndex = $names;

        // Build a leading-zero-insensitive index so a feed code like "0055" or
        // "0106" resolves to the alias "55" / "106" (and vice-versa). Exact
        // matches still win; this is only a fallback.
        $norm = [];
        foreach ($schoolAlias as $sys => $codes) {
            foreach ($codes as $code => $id) {
                $norm[$sys][self::normalizeSchoolCode((string) $code)] ??= $id;
            }
        }
        $this->schoolAliasNorm = $norm;
    }

    /** Canonical school code for matching: drop leading zeros (keep at least "0"). */
    public static function normalizeSchoolCode(string $code): string
    {
        $c = ltrim(trim($code), '0');
        return $c === '' ? '0' : $c;
    }

    /** Canonical school name for matching: lowercased, punctuation folded to spaces. */
    public static function normalizeSchoolName(string $name): string
    {
        $n = mb_strtolower(trim($name), 'UTF-8');
        $n = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n) ?? $n; // punctuation -> space
        return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
    }

    /** Resolve a school name to school_id (case-/punctuation-insensitive); null if unknown. */
    public function resolveSchoolByName(?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        return $this->schoolNameIndex[self::normalizeSchoolName($name)] ?? null;
    }

    /** Resolve a job code to a person_type via the position map; null if unmapped. */
    public function resolvePositionType(?string $jobCode): ?string
    {
        if ($jobCode === null || trim($jobCode) === '') {
            return null;
        }
        return $this->positionTypeMap[mb_strtolower(trim($jobCode), 'UTF-8')] ?? null;
    }

    /** Resolve a school code to school_id for an alias group (leading-zero tolerant). */
    public function resolveSchool(string $aliasSystem, ?string $code): ?int
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        $code = trim($code);
        return $this->schoolAlias[$aliasSystem][$code]
            ?? $this->schoolAliasNorm[$aliasSystem][self::normalizeSchoolCode($code)]
            ?? null;
    }

    public static function fromDb(?PDO $db = null): self
    {
        $db ??= Db::connect(Db::ROLE_APP);

        $alias = [];
        foreach ($db->query('SELECT system, code, school_id FROM school_code_alias')->fetchAll() as $r) {
            $alias[$r['system']][(string) $r['code']] = (int) $r['school_id'];
        }
        $eth = [];
        foreach ($db->query('SELECT source_value, alsde_code FROM ethnicity_map')->fetchAll() as $r) {
            $eth[mb_strtolower(trim((string) $r['source_value']), 'UTF-8')] = (string) $r['alsde_code'];
        }
        $names = [];
        foreach ($db->query('SELECT school_id, name FROM school')->fetchAll() as $r) {
            $names[(string) $r['name']] = (int) $r['school_id'];
        }
        $positions = [];
        foreach ($db->query('SELECT job_code, person_type FROM position_type_map')->fetchAll() as $r) {
            $positions[(string) $r['job_code']] = (string) $r['person_type'];
        }
        return new self($alias, $eth, $names, $positions);
    }

    /**
     * @param array<string,mixed> $raw  the raw CSV row keyed by header
     * @param array<string,string> $map logical field => CSV header
     * @param ?string $crosswalkSystem person_source_id.system (defaults to $system)
     * @param ?string $aliasSystem school_code_alias group to resolve codes (defaults to $system)
     * @param ?string $defaultType person_type when the feed doesn't specify one
     */
    public function normalize(
        array $raw,
        string $system,
        array $map,
        ?string $crosswalkSystem = null,
        ?string $aliasSystem = null,
        ?string $defaultType = null
    ): NormalizedRow {
        $aliasSystem ??= $system;
        $get = static function (string $field) use ($raw, $map): ?string {
            $header = $map[$field] ?? null;
            if ($header === null || !array_key_exists($header, $raw)) {
                return null;
            }
            $v = trim((string) $raw[$header]);
            return $v === '' ? null : $v;
        };

        $warnings = [];

        // School -> school_id. Resolution order:
        //   1. If the feed maps a school *name* column, match it to a known school.
        //      A present-but-unmatched name is a HARD ERROR (throws) — a mistyped
        //      or unknown building must not silently drop the assignment; the
        //      operator fixes the feed or adds the school, then re-imports.
        //   2. Otherwise fall back to the numeric school code / alias group. An
        //      unmapped code is a non-fatal warning (legacy behavior).
        $schoolName = $get('school_name');
        $schoolCode = $get('school_code');
        $schoolId = null;
        if ($schoolName !== null) {
            $schoolId = $this->resolveSchoolByName($schoolName);
            if ($schoolId === null) {
                throw new UnmatchedSchoolException(sprintf(
                    "School name '%s' does not match any known school (row: %s %s). "
                    . 'Fix the feed spelling or add the school to the reference table.',
                    $schoolName,
                    (string) ($get('first') ?? ''),
                    (string) ($get('last') ?? '')
                ));
            }
        } elseif ($schoolCode !== null) {
            $schoolId = $this->resolveSchool($aliasSystem, $schoolCode);
            if ($schoolId === null) {
                $warnings[] = "Unmapped {$aliasSystem} school code '{$schoolCode}'.";
            }
        }

        // Ethnicity -> ALSDE code.
        $ethSource = $get('ethnicity');
        $ethCode = null;
        if ($ethSource !== null) {
            $ethCode = $this->ethnicityMap[mb_strtolower($ethSource, 'UTF-8')] ?? null;
            if ($ethCode === null) {
                $warnings[] = "Unmapped ethnicity value '{$ethSource}'.";
            }
        }

        // Person type. An explicit feed column wins; otherwise classify by the
        // position map (NextGen JOB CODE -> faculty/staff/...); otherwise the
        // source default (PersonWriter falls back to 'staff' on create). Unmapped
        // job codes are not row warnings — the map is allowed to be partial —
        // they're surfaced on the Reference page (Positions tab) instead.
        $jobCode = $get('job_code');
        $personType = self::normType($get('person_type'))
            ?? $this->resolvePositionType($jobCode)
            ?? $defaultType;

        $primaryRaw = $get('is_primary');
        $isPrimary = $primaryRaw === null
            ? true
            : in_array(mb_strtolower($primaryRaw), ['1', 'y', 'yes', 'true', 'primary'], true);

        return new NormalizedRow(
            system: $system,
            sourceKey: (string) ($get('source_key') ?? ''),
            crosswalkSystem: $crosswalkSystem ?? $system,
            firstName: (string) ($get('first') ?? ''),
            lastName: (string) ($get('last') ?? ''),
            middleName: $get('middle'),
            preferredName: $get('preferred'),
            dob: self::parseDate($get('dob')),
            gender: $get('gender'),
            employeeId: $get('employee_id'),
            schoolCode: $schoolCode,
            schoolName: $schoolName,
            schoolId: $schoolId,
            ethnicitySource: $ethSource,
            ethnicityCode: $ethCode,
            alsdeId: $get('alsde_id'),
            personType: $personType,
            title: $get('title'),
            jobCode: $jobCode,
            fte: $get('fte'),
            hireDate: self::parseDate($get('hire_date')),
            positionStartDate: self::parseDate($get('position_start_date')),
            endDate: self::parseDate($get('end_date')),
            hrEmail: $get('hr_email'),
            positionNumber: $get('position_number'),
            cctrDescription: $get('cctr_description'),
            phone: $get('phone'),
            address1: $get('address1'),
            address2: $get('address2'),
            city: $get('city'),
            stateCode: $get('state_code'),
            zipCode: $get('zip_code'),
            isPrimary: $isPrimary,
            warnings: $warnings,
            raw: $raw,
        );
    }

    /** Parse common date formats to Y-m-d; null on blank/unparseable. */
    public static function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'Y/m/d', 'm-d-Y', 'd-M-Y', 'M d, Y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $fmt, $value);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    /** Map a free-text person/staff type to a schema enum value, or null. */
    private static function normType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }
        $t = mb_strtolower(trim($type));
        return match (true) {
            str_contains($t, 'faculty'), str_contains($t, 'teacher') => 'faculty',
            str_contains($t, 'contract')                              => 'contractor',
            str_contains($t, 'sub')                                   => 'sub',
            str_contains($t, 'intern')                                => 'intern',
            str_contains($t, 'staff')                                 => 'staff',
            default                                                   => null,
        };
    }
}
