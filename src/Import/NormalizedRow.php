<?php

declare(strict_types=1);

namespace App\Import;

/**
 * A single incoming feed row after normalization: source identity, HR-owned
 * demographics, and resolved reference values (school_id, ethnicity_code).
 * `warnings` collects non-fatal normalization issues (e.g. unmapped school code)
 * that are surfaced on the staged row. Immutable.
 */
final class NormalizedRow
{
    /** @param string[] $warnings @param array<string,mixed> $raw */
    public function __construct(
        public readonly string $system,        // batch system: nextgen|powerschool|manual|intern|sub|contractor
        public readonly string $sourceKey,     // this system's id for the row
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $crosswalkSystem = null, // person_source_id.system (defaults to $system)
        public readonly ?string $middleName = null,
        public readonly ?string $preferredName = null,
        public readonly ?string $dob = null,           // Y-m-d or null
        public readonly ?string $gender = null,
        public readonly ?string $employeeId = null,
        public readonly ?string $schoolCode = null,
        public readonly ?int $schoolId = null,         // resolved
        public readonly ?string $ethnicitySource = null,
        public readonly ?string $ethnicityCode = null, // resolved ALSDE code
        public readonly ?string $personType = null,
        public readonly ?string $title = null,
        public readonly ?string $jobCode = null,
        public readonly ?string $fte = null,
        public readonly ?string $hireDate = null,
        public readonly ?string $endDate = null,
        public readonly bool $isPrimary = true,
        public readonly array $warnings = [],
        public readonly array $raw = [],
    ) {
    }

    /** The crosswalk system this row's source id belongs to (matcher + person_source_id). */
    public function sourceSystem(): string
    {
        return $this->crosswalkSystem ?? $this->system;
    }

    /** Required fields present to attempt matching at all. */
    public function isMatchable(): bool
    {
        return trim($this->sourceKey) !== '' && trim($this->lastName) !== '' && trim($this->firstName) !== '';
    }
}
