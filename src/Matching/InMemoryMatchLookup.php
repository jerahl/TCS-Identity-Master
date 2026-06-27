<?php

declare(strict_types=1);

namespace App\Matching;

/**
 * In-memory MatchLookup for tests (and dry-run experiments). Seed it with
 * persons and their source ids; it answers the matcher's queries with no DB.
 */
final class InMemoryMatchLookup implements MatchLookup
{
    /**
     * @param array<int,array{person_id:int,first_name:string,last_name:string,dob:?string,employee_id:?string}> $persons
     * @param array<int,array{system:string,source_key:string,person_id:int}> $sourceIds
     */
    public function __construct(
        private array $persons = [],
        private array $sourceIds = [],
    ) {
    }

    public function addPerson(int $id, string $first, string $last, ?string $dob = null, ?string $employeeId = null): void
    {
        $this->persons[] = ['person_id' => $id, 'first_name' => $first, 'last_name' => $last, 'dob' => $dob, 'employee_id' => $employeeId];
    }

    public function addSourceId(string $system, string $sourceKey, int $personId): void
    {
        $this->sourceIds[] = ['system' => $system, 'source_key' => $sourceKey, 'person_id' => $personId];
    }

    public function findPersonIdBySourceId(string $system, string $sourceKey): ?int
    {
        foreach ($this->sourceIds as $s) {
            if ($s['system'] === $system && (string) $s['source_key'] === (string) $sourceKey) {
                return $s['person_id'];
            }
        }
        return null;
    }

    public function findPersonIdByEmployeeId(string $employeeId): ?int
    {
        foreach ($this->persons as $p) {
            if (($p['employee_id'] ?? null) !== null && (string) $p['employee_id'] === (string) $employeeId) {
                return $p['person_id'];
            }
        }
        return null;
    }

    public function findByLastName(string $lastName): array
    {
        $want = mb_strtolower(trim($lastName), 'UTF-8');
        $out = [];
        foreach ($this->persons as $p) {
            if (mb_strtolower(trim($p['last_name']), 'UTF-8') === $want) {
                $out[] = ['person_id' => $p['person_id'], 'first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'dob' => $p['dob'] ?? null];
            }
        }
        return $out;
    }
}
