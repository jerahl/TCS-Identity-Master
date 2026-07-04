<?php

declare(strict_types=1);

namespace App\Matching;

use PDO;

/**
 * Production MatchLookup backed by the database (prepared statements only).
 * Last-name matching relies on the column's case-insensitive utf8mb4 collation;
 * the matcher does the finer first-name/DOB normalization in PHP.
 */
final class PdoMatchLookup implements MatchLookup
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findPersonIdBySourceId(string $system, string $sourceKey): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT person_id FROM person_source_id WHERE system = :system AND source_key = :key LIMIT 1'
        );
        $stmt->execute([':system' => $system, ':key' => $sourceKey]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function findPersonsByEmployeeId(string $employeeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT person_id, first_name, last_name FROM person WHERE employee_id = :emp AND employee_id <> ''"
        );
        $stmt->execute([':emp' => $employeeId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'person_id' => (int) $r['person_id'],
                'first_name' => (string) $r['first_name'],
                'last_name' => (string) $r['last_name'],
            ];
        }
        return $out;
    }

    public function findByLastName(string $lastName): array
    {
        $stmt = $this->db->prepare(
            'SELECT person_id, first_name, last_name, dob FROM person WHERE last_name = :last'
        );
        $stmt->execute([':last' => $lastName]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'person_id' => (int) $r['person_id'],
                'first_name' => (string) $r['first_name'],
                'last_name' => (string) $r['last_name'],
                'dob' => $r['dob'] !== null ? (string) $r['dob'] : null,
            ];
        }
        return $out;
    }
}
