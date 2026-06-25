<?php

declare(strict_types=1);

namespace App\Import;

use InvalidArgumentException;

/**
 * Maps a feed's CSV headers to the logical fields the normalizer understands.
 * Defaults are documented expectations for each source; adjust here (or supply a
 * custom map to the importer) to match the district's real export headers.
 *
 * Logical fields: source_key, employee_id, first, middle, last, preferred, dob,
 * gender, ethnicity, school_code, person_type, title, job_code, fte, hire_date,
 * end_date, is_primary.
 */
final class ColumnMap
{
    private const MAPS = [
        // NextGen HR export. source_key == the HR id we crosswalk as 'nextgen'.
        'nextgen' => [
            'source_key'  => 'EmployeeID',
            'employee_id' => 'EmployeeID',
            'first'       => 'FirstName',
            'middle'      => 'MiddleName',
            'last'        => 'LastName',
            'preferred'   => 'PreferredName',
            'dob'         => 'DOB',
            'gender'      => 'Gender',
            'ethnicity'   => 'Ethnicity',
            'school_code' => 'HomeSchoolCode',
            'person_type' => 'PersonType',
            'title'       => 'JobTitle',
            'job_code'    => 'JobCode',
            'fte'         => 'FTE',
            'hire_date'   => 'HireDate',
            'end_date'    => 'EndDate',
            'is_primary'  => 'Primary',
        ],
        // PowerSchool staff extract. source_key == the PS person id (e.g. PST15241).
        'powerschool' => [
            'source_key'  => 'PSID',
            'employee_id' => 'TeacherNumber',
            'first'       => 'First_Name',
            'middle'      => 'Middle_Name',
            'last'        => 'Last_Name',
            'preferred'   => 'Preferred_Name',
            'dob'         => 'DOB',
            'gender'      => 'Gender',
            'ethnicity'   => 'Ethnicity',
            'school_code' => 'SchoolID',
            'person_type' => 'StaffType',
            'title'       => 'Title',
            'job_code'    => 'JobCode',
            'fte'         => 'FTE',
            'hire_date'   => 'HireDate',
            'end_date'    => 'EndDate',
            'is_primary'  => 'Primary',
        ],
    ];

    /** @return array<string,string> logical field => CSV header */
    public static function for(string $system): array
    {
        if (!isset(self::MAPS[$system])) {
            throw new InvalidArgumentException("No column map for system '{$system}'.");
        }
        return self::MAPS[$system];
    }
}
