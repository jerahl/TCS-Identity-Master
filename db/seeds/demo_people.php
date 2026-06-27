<?php

declare(strict_types=1);

/**
 * DEV-ONLY demo fixtures consumed by bin/seed_demo.php. Mirrors the design
 * mockup's sample roster so the UI renders realistically pre-importers. Not real
 * data; safe to edit. `uuid` is the stable key (expanded to a full CHAR(36)).
 */

$uuid = static fn(string $short, int $i): string =>
    $short . '-9f4a-11ee-8c2a-' . str_pad((string) $i, 12, '0', STR_PAD_LEFT);

$ad = static fn(string $g): array => ['ad', $g, 1];
$goog = static fn(string $e): array => ['google', $e, 1];

return [
    [
        'uuid' => $uuid('2f9a4c11', 1), 'type' => 'faculty', 'status' => 'active',
        'first' => 'Jennifer', 'middle' => 'Sue', 'last' => 'Marsh', 'preferred' => 'Jen',
        'dob' => '1986-04-12', 'gender' => 'Female', 'eth_src' => 'White', 'eth_code' => '5',
        'alsde' => 'AL0156241', 'emp' => '15241', 'school' => 'Central High School',
        'hire' => '2014-08-18', 'end' => '', 'username' => 'jmarsh', 'email' => 'jmarsh@tcs.k12.al.us',
        'sor' => 'nextgen', 'notes' => 'Department chair, Mathematics. Approved for after-hours building access.',
        'sources' => [['nextgen', '15241', 1], ['powerschool', 'PST15241', 1], $ad('{8f3a9d12-44e1}'), $goog('jmarsh@tcs.k12.al.us')],
        'assignments' => [
            ['school' => 'Central High School', 'title' => 'Teacher, Mathematics', 'fte' => '1.0', 'primary' => true, 'eff' => '2014-08-18', 'end' => ''],
            ['school' => 'Tuscaloosa Magnet School', 'title' => 'Adjunct, STEM Club', 'fte' => '0.0', 'primary' => false, 'eff' => '2022-09-01', 'end' => ''],
        ],
        'lifecycle' => [
            ['type' => 'username_assigned', 'summary' => 'jmarsh provisioned in AD and Google', 'actor' => 'OneSync', 'at' => '2014-08-19 06:02:00'],
            ['type' => 'update', 'summary' => 'Preferred name set to "Jen"', 'actor' => 'A. Reyes', 'at' => '2021-03-11 14:22:00'],
            ['type' => 'create', 'summary' => 'Imported from NextGen feed', 'actor' => 'NextGen', 'at' => '2014-08-15 03:00:00'],
        ],
        'sync' => [
            ['dest' => 'Active Directory', 'dtype' => 'ActiveDirectory', 'action' => 'Edit', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
            ['dest' => 'Google Workspace', 'dtype' => 'GSuite', 'action' => 'Edit', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
            ['dest' => 'PowerSchool', 'dtype' => 'CSV', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 02:00:00'],
            ['dest' => 'Raptor', 'dtype' => 'CSV', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 02:10:00'],
        ],
    ],
    [
        'uuid' => $uuid('7b1e0d83', 2), 'type' => 'staff', 'status' => 'active',
        'first' => 'Marcus', 'middle' => 'D', 'last' => 'Okafor', 'preferred' => '',
        'dob' => '1979-11-30', 'gender' => 'Male', 'eth_src' => 'Black or African American', 'eth_code' => '3',
        'alsde' => 'AL0149883', 'emp' => '14988', 'school' => 'Northridge High School',
        'hire' => '2009-07-01', 'end' => '', 'username' => 'mokafor', 'email' => 'mokafor@tcs.k12.al.us',
        'sor' => 'nextgen', 'notes' => 'Building network technician.',
        'sources' => [['nextgen', '14988', 1], ['powerschool', 'PST14988', 1], $ad('{a02c7e55-9b3d}'), $goog('mokafor@tcs.k12.al.us')],
        'assignments' => [['school' => 'Northridge High School', 'title' => 'IT Support Technician', 'fte' => '1.0', 'primary' => true, 'eff' => '2009-07-01', 'end' => '']],
        'lifecycle' => [
            ['type' => 'username_assigned', 'summary' => 'mokafor provisioned', 'actor' => 'OneSync', 'at' => '2009-07-02 06:01:00'],
            ['type' => 'create', 'summary' => 'Imported from NextGen feed', 'actor' => 'NextGen', 'at' => '2009-06-28 03:00:00'],
        ],
        'sync' => [
            ['dest' => 'Active Directory', 'dtype' => 'ActiveDirectory', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
            ['dest' => 'Google Workspace', 'dtype' => 'GSuite', 'action' => 'Edit', 'status' => 'Fail', 'at' => '2026-06-25 06:03:00', 'msg' => 'Google API: quota exceeded — retry scheduled for next run.'],
        ],
    ],
    [
        'uuid' => $uuid('c4d8f290', 3), 'type' => 'intern', 'status' => 'active',
        'first' => 'Elena', 'middle' => '', 'last' => 'Ruiz', 'preferred' => '',
        'dob' => '2001-06-23', 'gender' => 'Female', 'eth_src' => 'Hispanic/Latino', 'eth_code' => '4',
        'alsde' => '', 'emp' => '', 'school' => 'University Place Elementary',
        'hire' => '2025-08-25', 'end' => '2026-05-22', 'username' => 'eruiz', 'email' => 'eruiz@tcs.k12.al.us',
        'sor' => 'manual', 'notes' => 'Teaching intern, University of Alabama placement. Intern ID 88.',
        'sources' => [['intern_csv', '88', 1], $ad('{d51b3aa0-77f2}'), $goog('eruiz@tcs.k12.al.us')],
        'assignments' => [['school' => 'University Place Elementary', 'title' => 'Teaching Intern', 'fte' => '0.5', 'primary' => true, 'eff' => '2025-08-25', 'end' => '2026-05-22', 'source' => 'manual']],
        'lifecycle' => [
            ['type' => 'username_assigned', 'summary' => 'eruiz provisioned for intern program', 'actor' => 'OneSync', 'at' => '2025-08-21 06:03:00'],
            ['type' => 'create', 'summary' => 'Manual entry — intern program', 'actor' => 'A. Reyes', 'at' => '2025-08-20 10:14:00'],
        ],
        'sync' => [
            ['dest' => 'Active Directory', 'dtype' => 'ActiveDirectory', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
            ['dest' => 'Google Workspace', 'dtype' => 'GSuite', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
        ],
    ],
    [
        'uuid' => $uuid('1a6b9e44', 4), 'type' => 'faculty', 'status' => 'active',
        'first' => 'David', 'middle' => 'R', 'last' => 'Coleman', 'preferred' => 'Dave',
        'dob' => '1990-02-08', 'gender' => 'Male', 'eth_src' => 'White', 'eth_code' => '5',
        'alsde' => 'AL0151027', 'emp' => '15102', 'school' => 'Paul W. Bryant High School',
        'hire' => '2018-08-13', 'end' => '', 'username' => 'dcoleman', 'email' => 'dcoleman@tcs.k12.al.us',
        'sor' => 'nextgen', 'notes' => 'Head coach, varsity baseball.',
        'sources' => [['nextgen', '15102', 1], ['powerschool', 'PST15102', 1], $ad('{6c2f8b91-1de4}'), $goog('dcoleman@tcs.k12.al.us')],
        'assignments' => [['school' => 'Paul W. Bryant High School', 'title' => 'Teacher, Physical Education', 'fte' => '1.0', 'primary' => true, 'eff' => '2018-08-13', 'end' => '']],
        'lifecycle' => [
            ['type' => 'username_assigned', 'summary' => 'dcoleman provisioned', 'actor' => 'OneSync', 'at' => '2018-08-14 06:02:00'],
            ['type' => 'create', 'summary' => 'Imported from NextGen feed', 'actor' => 'NextGen', 'at' => '2018-08-10 03:00:00'],
        ],
        'sync' => [
            ['dest' => 'Active Directory', 'dtype' => 'ActiveDirectory', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
            ['dest' => 'Google Workspace', 'dtype' => 'GSuite', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
        ],
    ],
    [
        'uuid' => $uuid('9e3c1f70', 5), 'type' => 'faculty', 'status' => 'pending',
        'first' => 'Sarah', 'middle' => 'L', 'last' => 'Whitfield', 'preferred' => '',
        'dob' => '1994-09-17', 'gender' => 'Female', 'eth_src' => 'Asian', 'eth_code' => '2',
        'alsde' => 'AL0158902', 'emp' => '15890', 'school' => 'Tuscaloosa Magnet School',
        'hire' => '2026-08-10', 'end' => '', 'username' => '', 'email' => '',
        'sor' => 'nextgen', 'notes' => 'New hire — fall 2026. Awaiting activation before sync.',
        'sources' => [['nextgen', '15890', 1], ['powerschool', 'PST15890', 1]],
        'assignments' => [['school' => 'Tuscaloosa Magnet School', 'title' => 'Teacher, Science', 'fte' => '1.0', 'primary' => true, 'eff' => '2026-08-10', 'end' => '']],
        'lifecycle' => [
            ['type' => 'create', 'summary' => 'Imported from NextGen feed — pending activation', 'actor' => 'NextGen', 'at' => '2026-06-20 03:00:00'],
        ],
        'sync' => [],
    ],
    [
        'uuid' => $uuid('b8704a2c', 6), 'type' => 'contractor', 'status' => 'active',
        'first' => 'Tomás', 'middle' => '', 'last' => 'Herrera', 'preferred' => 'Tom',
        'dob' => '1983-12-05', 'gender' => 'Male', 'eth_src' => 'Hispanic/Latino', 'eth_code' => '4',
        'alsde' => '', 'emp' => 'C-2204', 'school' => 'District Office',
        'hire' => '2024-01-08', 'end' => '2026-12-31', 'username' => 'therrera', 'email' => 'therrera@tcs.k12.al.us',
        'sor' => 'manual', 'notes' => 'HVAC contractor — facilities. Time-limited account.',
        'sources' => [$ad('{e90a4c12-3f88}'), $goog('therrera@tcs.k12.al.us')],
        'assignments' => [['school' => 'District Office', 'title' => 'Contractor — Facilities', 'fte' => '1.0', 'primary' => true, 'eff' => '2024-01-08', 'end' => '2026-12-31', 'source' => 'manual']],
        'lifecycle' => [
            ['type' => 'username_assigned', 'summary' => 'therrera provisioned (contractor)', 'actor' => 'OneSync', 'at' => '2024-01-09 06:02:00'],
            ['type' => 'create', 'summary' => 'Manual entry — contractor onboarding', 'actor' => 'A. Reyes', 'at' => '2024-01-05 09:30:00'],
        ],
        'sync' => [
            ['dest' => 'Active Directory', 'dtype' => 'ActiveDirectory', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
            ['dest' => 'Google Workspace', 'dtype' => 'GSuite', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
        ],
    ],
    [
        'uuid' => $uuid('34de8801', 7), 'type' => 'staff', 'status' => 'disabled',
        'first' => 'Angela', 'middle' => 'M', 'last' => 'Brooks', 'preferred' => '',
        'dob' => '1975-03-21', 'gender' => 'Female', 'eth_src' => 'Black or African American', 'eth_code' => '3',
        'alsde' => 'AL0137205', 'emp' => '13720', 'school' => 'Westlawn Middle School',
        'hire' => '2005-08-01', 'end' => '', 'username' => 'abrooks', 'email' => 'abrooks@tcs.k12.al.us',
        'sor' => 'nextgen', 'notes' => 'On extended leave — account disabled, not terminated.',
        'sources' => [['nextgen', '13720', 1], ['powerschool', 'PST13720', 1], $ad('{17bd9e44-22a1}'), $goog('abrooks@tcs.k12.al.us')],
        'assignments' => [['school' => 'Westlawn Middle School', 'title' => 'Administrative Assistant', 'fte' => '1.0', 'primary' => true, 'eff' => '2005-08-01', 'end' => '']],
        'lifecycle' => [
            ['type' => 'disable', 'summary' => 'Extended leave — OneSync disabled AD + Google', 'actor' => 'A. Reyes', 'at' => '2026-04-18 11:02:00'],
            ['type' => 'username_assigned', 'summary' => 'abrooks provisioned', 'actor' => 'OneSync', 'at' => '2005-08-02 06:00:00'],
        ],
        'sync' => [
            ['dest' => 'Active Directory', 'dtype' => 'ActiveDirectory', 'action' => 'Disable', 'status' => 'Success', 'at' => '2026-04-18 11:02:00'],
            ['dest' => 'Google Workspace', 'dtype' => 'GSuite', 'action' => 'Disable', 'status' => 'Success', 'at' => '2026-04-18 11:02:00'],
        ],
    ],
    [
        'uuid' => $uuid('aa12f6b9', 8), 'type' => 'faculty', 'status' => 'terminated',
        'first' => 'Robert', 'middle' => 'K', 'last' => 'Tran', 'preferred' => 'Rob',
        'dob' => '1988-07-19', 'gender' => 'Male', 'eth_src' => 'Asian', 'eth_code' => '2',
        'alsde' => 'AL0144013', 'emp' => '14401', 'school' => 'Eastwood Middle School',
        'hire' => '2012-08-15', 'end' => '2025-06-30', 'username' => 'rtran', 'email' => 'rtran@tcs.k12.al.us',
        'sor' => 'nextgen', 'notes' => 'Resigned end of 2024-25 year. Accounts deprovisioned.',
        'sources' => [['nextgen', '14401', 0], ['powerschool', 'PST14401', 0], $ad('{c771ab09-8e2f}'), $goog('rtran@tcs.k12.al.us')],
        'assignments' => [['school' => 'Eastwood Middle School', 'title' => 'Teacher, Social Studies', 'fte' => '1.0', 'primary' => true, 'eff' => '2012-08-15', 'end' => '2025-06-30']],
        'lifecycle' => [
            ['type' => 'terminate', 'summary' => 'End date reached — OneSync deprovisioned all accounts', 'actor' => 'system', 'at' => '2025-07-05 02:00:00'],
            ['type' => 'username_assigned', 'summary' => 'rtran provisioned', 'actor' => 'OneSync', 'at' => '2012-08-16 06:01:00'],
        ],
        'sync' => [
            ['dest' => 'Active Directory', 'dtype' => 'ActiveDirectory', 'action' => 'Disable', 'status' => 'Success', 'at' => '2025-07-05 02:00:00'],
            ['dest' => 'Google Workspace', 'dtype' => 'GSuite', 'action' => 'Disable', 'status' => 'Success', 'at' => '2025-07-05 02:00:00'],
        ],
    ],
    [
        'uuid' => $uuid('5fb27c08', 9), 'type' => 'sub', 'status' => 'pending',
        'first' => 'Priya', 'middle' => '', 'last' => 'Nair', 'preferred' => '',
        'dob' => '1996-01-29', 'gender' => 'Female', 'eth_src' => 'Asian', 'eth_code' => '2',
        'alsde' => '', 'emp' => 'S-9912', 'school' => 'University Place Elementary',
        'hire' => '2026-06-22', 'end' => '', 'username' => '', 'email' => '',
        'sor' => 'manual', 'notes' => 'Long-term substitute pool. Awaiting activation.',
        'sources' => [['manual', '9912', 1]],
        'assignments' => [['school' => 'University Place Elementary', 'title' => 'Substitute Teacher', 'fte' => '0.0', 'primary' => true, 'eff' => '2026-06-22', 'end' => '', 'source' => 'manual']],
        'lifecycle' => [
            ['type' => 'create', 'summary' => 'Manual entry — substitute pool', 'actor' => 'A. Reyes', 'at' => '2026-06-22 08:40:00'],
        ],
        'sync' => [],
    ],
    [
        'uuid' => $uuid('e2891d4f', 10), 'type' => 'staff', 'status' => 'active',
        'first' => 'Keisha', 'middle' => 'A', 'last' => 'Daniels', 'preferred' => '',
        'dob' => '1981-10-02', 'gender' => 'Female', 'eth_src' => 'Two or More Races', 'eth_code' => '7',
        'alsde' => 'AL0120447', 'emp' => '12044', 'school' => 'District Office',
        'hire' => '2007-02-12', 'end' => '', 'username' => 'kdaniels', 'email' => 'kdaniels@tcs.k12.al.us',
        'sor' => 'nextgen', 'notes' => 'Payroll specialist, central office.',
        'sources' => [['nextgen', '12044', 1], ['powerschool', 'PST12044', 1], $ad('{4b8e1c70-66da}'), $goog('kdaniels@tcs.k12.al.us')],
        'assignments' => [['school' => 'District Office', 'title' => 'Payroll Specialist', 'fte' => '1.0', 'primary' => true, 'eff' => '2007-02-12', 'end' => '']],
        'lifecycle' => [
            ['type' => 'username_assigned', 'summary' => 'kdaniels provisioned', 'actor' => 'OneSync', 'at' => '2007-02-13 06:00:00'],
            ['type' => 'create', 'summary' => 'Imported from NextGen feed', 'actor' => 'NextGen', 'at' => '2007-02-09 03:00:00'],
        ],
        'sync' => [
            ['dest' => 'Active Directory', 'dtype' => 'ActiveDirectory', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
            ['dest' => 'Google Workspace', 'dtype' => 'GSuite', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
        ],
    ],
    [
        'uuid' => $uuid('7c0a9b35', 11), 'type' => 'faculty', 'status' => 'active',
        'first' => 'James', 'middle' => 'W', 'last' => 'Whitlow', 'preferred' => 'Jim',
        'dob' => '1992-05-14', 'gender' => 'Male', 'eth_src' => 'White', 'eth_code' => '5',
        'alsde' => 'AL0156331', 'emp' => '15633', 'school' => 'Northridge High School',
        'hire' => '2020-08-17', 'end' => '', 'username' => 'jwhitlow', 'email' => 'jwhitlow@tcs.k12.al.us',
        'sor' => 'nextgen', 'notes' => 'Teacher, English. Yearbook advisor.',
        'sources' => [['nextgen', '15633', 1], ['powerschool', 'PST15633', 1], $ad('{f3219a87-0cd4}'), $goog('jwhitlow@tcs.k12.al.us')],
        'assignments' => [['school' => 'Northridge High School', 'title' => 'Teacher, English', 'fte' => '1.0', 'primary' => true, 'eff' => '2020-08-17', 'end' => '']],
        'lifecycle' => [
            ['type' => 'username_assigned', 'summary' => 'jwhitlow provisioned', 'actor' => 'OneSync', 'at' => '2020-08-18 06:01:00'],
            ['type' => 'create', 'summary' => 'Imported from NextGen feed', 'actor' => 'NextGen', 'at' => '2020-08-14 03:00:00'],
        ],
        'sync' => [
            ['dest' => 'Active Directory', 'dtype' => 'ActiveDirectory', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
            ['dest' => 'Google Workspace', 'dtype' => 'GSuite', 'action' => 'NoChange', 'status' => 'Success', 'at' => '2026-06-25 06:03:00'],
        ],
    ],
];
