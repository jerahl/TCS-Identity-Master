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

    /**
     * All persons carrying this employee_id. The column is NOT a globally unique
     * keyspace — nextgen/powerschool share the real HR number, but sub/contractor
     * feeds overload it with their own SubID/ContractorID, so a value can collide
     * across two different humans. The matcher corroborates by name and refuses to
     * auto-link on a collision, so it needs every hit (with names), not just one.
     *
     * @return array<int,array{person_id:int, first_name:string, last_name:string}>
     */
    public function findPersonsByEmployeeId(string $employeeId): array;

    /**
     * Persons sharing this last name (case-insensitive). The matcher does the
     * first-name/DOB scoring; the lookup just narrows the field.
     *
     * @return array<int,array{person_id:int, first_name:string, last_name:string, dob:?string}>
     */
    public function findByLastName(string $lastName): array;
}
