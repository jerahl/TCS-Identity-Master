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
        // NextGen HR export (ITExtract.csv) — real district headers.
        // No DOB / middle / preferred / type columns in this feed.
        'nextgen' => [
            'source_key'  => 'Employee Number',
            'employee_id' => 'Employee Number',
            'first'       => 'First Name',
            'last'        => 'Last Name',
            'gender'      => 'Gender Type',
            'ethnicity'   => 'Ethnicity Description',
            'school_code' => 'Location Code',
            'title'       => 'Job Code Desc',
            'job_code'    => 'JOB CODE',
            'hire_date'   => 'Hire Date',
            'end_date'    => 'Position End Date',
        ],
        // PowerSchool extract is HEADERLESS — values are 0-based COLUMN INDEXES,
        // not header names (see ImportSource: 'powerschool' is headerless).
        // Discover the layout with:
        //   php bin/feed_headers.php --system=powerschool --file=<file>
        // then set the indexes below. PLACEHOLDER — confirm before relying on it.
        'powerschool' => [
            'source_key'  => 0,
        ],
        // Intern roster (e.g. university placements). No employee id; school code
        // is a PowerSchool SchoolID. person_type is forced to 'intern'.
        'intern' => [
            'source_key'  => 'InternID',
            'first'       => 'FirstName',
            'middle'      => 'MiddleName',
            'last'        => 'LastName',
            'preferred'   => 'PreferredName',
            'dob'         => 'DOB',
            'gender'      => 'Gender',
            'ethnicity'   => 'Ethnicity',
            'school_code' => 'SchoolID',
            'title'       => 'Placement',
            'fte'         => 'FTE',
            'hire_date'   => 'StartDate',
            'end_date'    => 'EndDate',
            'is_primary'  => 'Primary',
        ],
        // Long-term substitute pool. Has an HR sub id.
        'sub' => [
            'source_key'  => 'SubID',
            'employee_id' => 'SubID',
            'first'       => 'FirstName',
            'middle'      => 'MiddleName',
            'last'        => 'LastName',
            'preferred'   => 'PreferredName',
            'dob'         => 'DOB',
            'gender'      => 'Gender',
            'ethnicity'   => 'Ethnicity',
            'school_code' => 'SchoolID',
            'title'       => 'Assignment',
            'fte'         => 'FTE',
            'hire_date'   => 'StartDate',
            'end_date'    => 'EndDate',
            'is_primary'  => 'Primary',
        ],
        // Contract / vendor employees (HVAC, IT, etc.). Time-limited.
        'contractor' => [
            'source_key'  => 'ContractorID',
            'employee_id' => 'ContractorID',
            'first'       => 'FirstName',
            'middle'      => 'MiddleName',
            'last'        => 'LastName',
            'preferred'   => 'PreferredName',
            'dob'         => 'DOB',
            'gender'      => 'Gender',
            'ethnicity'   => 'Ethnicity',
            'school_code' => 'SchoolID',
            'title'       => 'Role',
            'hire_date'   => 'StartDate',
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
