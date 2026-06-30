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
        // PowerSchool-sourced demographics NextGen doesn't carry.
        public readonly ?string $dob = null,       // Y-m-d (from PS)
        public readonly ?string $alsdeId = null,   // Alabama State ID (ALSID)
        // Contact / demographic fields pulled only to VERIFY against NextGen.
        // These are NOT written to the golden record (NextGen is source of record).
        public readonly ?string $email = null,
        public readonly ?string $gender = null,
        public readonly ?string $phone = null,
        public readonly ?string $address1 = null,
        public readonly ?string $city = null,
        public readonly ?string $stateCode = null,
        public readonly ?string $zipCode = null,
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
