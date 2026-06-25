<?php

declare(strict_types=1);

namespace App\Matching;

/**
 * The read port the matcher needs. Keeping it an interface lets the matcher be
 * unit-tested against an in-memory fake with zero DB, while production uses
 * PdoMatchLookup. The matcher itself contains no SQL.
 */
interface MatchLookup
{
    /** Person already linked to this (system, source_key), or null. */
    public function findPersonIdBySourceId(string $system, string $sourceKey): ?int;

    /** Person carrying this employee_id, or null. (employee_id is unique.) */
    public function findPersonIdByEmployeeId(string $employeeId): ?int;

    /**
     * Persons sharing this last name (case-insensitive). The matcher does the
     * first-name/DOB scoring; the lookup just narrows the field.
     *
     * @return array<int,array{person_id:int, first_name:string, last_name:string, dob:?string}>
     */
    public function findByLastName(string $lastName): array;
}
