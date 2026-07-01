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
 * gender, ethnicity, school_name, school_code, person_type, title, job_code, fte,
 * hire_date, position_start_date, end_date, is_primary, and the NextGen HR
 * contact/position fields hr_email, position_number, cctr_description, phone,
 * address1, address2, city, state_code, zip_code.
 *
 * School resolution: when a feed maps school_name, the normalizer matches that
 * name to a known school and ERRORS the row if it doesn't match; school_code is
 * the numeric fallback for feeds that only carry a building code. The operator
 * feeds below (intern/sub/contractor) map both, so a "School Name" column — when
 * present — takes precedence over the "SchoolID" code.
 */
final class ColumnMap
{
    private const MAPS = [
        // NextGen HR export (ITExtract.csv) — real district headers.
        // No DOB / middle / preferred / type columns in this feed.
        'nextgen' => [
            'source_key'          => 'Employee Number',
            'employee_id'         => 'Employee Number',
            'first'               => 'First Name',
            'last'                => 'Last Name',
            'hr_email'            => 'EMail Address',
            'gender'              => 'Gender Type',
            'ethnicity'           => 'Ethnicity Description',
            'school_code'         => 'Location Code',
            'position_number'     => 'Position Number',
            'cctr_description'    => 'CCTR Description',
            'title'               => 'Job Code Desc',
            'job_code'            => 'JOB CODE',
            'hire_date'           => 'Hire Date',
            'position_start_date' => 'Position Start Date',
            'end_date'            => 'Position End Date',
            'phone'               => 'Phone Number',
            'address1'            => 'Address 1',
            'address2'            => 'Address 2',
            'city'                => 'City',
            'state_code'          => 'State Code',
            'zip_code'            => 'Zip Code',
        ],
        // PowerSchool TEACHERS export. source_key = TEACHERS.ID — the stable PS
        // identity that AD mirrors as its uniqueId ("T" + TEACHERS.ID), so AD
        // accounts resolve to the same record. TeacherNumber is the NextGen
        // Employee Number, so PS rows auto-link to the same person as NextGen.
        // LoginID/Email_Addr are NOT imported — OneSync owns username/email.
        // School/title come from NextGen (the HR source of record).
        'powerschool' => [
            'source_key'  => 'TEACHERS.ID',
            'employee_id' => 'TEACHERS.TeacherNumber',
            'first'       => 'TEACHERS.First_Name',
            'last'        => 'TEACHERS.Last_Name',
        ],
        // Intern roster (e.g. university placements). No employee id. A "School
        // Name" column (matched to a known school) takes precedence over the
        // numeric SchoolID code. person_type is forced to 'intern'.
        'intern' => [
            'source_key'  => 'InternID',
            'first'       => 'FirstName',
            'middle'      => 'MiddleName',
            'last'        => 'LastName',
            'preferred'   => 'PreferredName',
            'dob'         => 'DOB',
            'gender'      => 'Gender',
            'ethnicity'   => 'Ethnicity',
            'school_name' => 'School Name',
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
            'school_name' => 'School Name',
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
            'school_name' => 'School Name',
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
