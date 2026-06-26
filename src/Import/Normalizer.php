<?php

declare(strict_types=1);

namespace App\Import;

use App\Db;
use PDO;

/**
 * Turns a raw CSV row (+ column map) into a NormalizedRow: trims fields, parses
 * dates to Y-m-d, and resolves reference codes via the maps —
 *   school code  -> school_id   (school_code_alias, per system)
 *   ethnicity    -> ALSDE code  (ethnicity_map)
 * Unresolved values are kept raw and recorded as warnings (surfaced on the staged
 * row) rather than silently dropped.
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

    /**
     * @param array<string,array<string,int>> $schoolAlias  [system][code] => school_id
     * @param array<string,string> $ethnicityMap            lower(source_value) => alsde_code
     */
    public function __construct(array $schoolAlias, array $ethnicityMap)
    {
        $this->schoolAlias = $schoolAlias;
        $this->ethnicityMap = $ethnicityMap;

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
        return new self($alias, $eth);
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

        // School code -> school_id (resolved against the alias group for this source).
        $schoolCode = $get('school_code');
        $schoolId = null;
        if ($schoolCode !== null) {
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
            schoolId: $schoolId,
            ethnicitySource: $ethSource,
            ethnicityCode: $ethCode,
            personType: self::normType($get('person_type')) ?? $defaultType,
            title: $get('title'),
            jobCode: $get('job_code'),
            fte: $get('fte'),
            hireDate: self::parseDate($get('hire_date')),
            endDate: self::parseDate($get('end_date')),
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
