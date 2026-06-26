<?php

declare(strict_types=1);

namespace App\Import;

/**
 * One combined PowerSchool person: identity + every TEACHERS.ID and every school
 * assignment. Produced by PowerSchoolBundle::combine().
 */
final class PsUser
{
    /**
     * @param string[] $teacherIds all TEACHERS.ID values for this user
     * @param array<int,array{code:string,primary:bool}> $schools school assignments
     */
    public function __construct(
        public readonly string $usersDcid,
        public readonly string $employeeId,
        public readonly string $firstName,
        public readonly string $middleName,
        public readonly string $lastName,
        public readonly ?string $title,
        public readonly ?string $classification,
        public readonly ?string $hireDate,
        public readonly ?string $endDate,
        public readonly array $teacherIds,
        public readonly array $schools,
    ) {
    }

    /** The primary school code (or null if none). */
    public function primarySchoolCode(): ?string
    {
        foreach ($this->schools as $s) {
            if ($s['primary']) {
                return $s['code'];
            }
        }
        return $this->schools[0]['code'] ?? null;
    }
}
