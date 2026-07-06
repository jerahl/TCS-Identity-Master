<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use PDO;

/**
 * Reference-data admin: the school and ethnicity maps that resolve incoming
 * source codes, plus the "unmapped values" surfaces (values seen in feeds/records
 * with no mapping) that block clean provisioning. Read-only in M6; editing +
 * RBAC arrive in M7.
 */
final class ReferenceService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
    }

    /** Schools with their per-system codes; flagged unmapped if missing an OU. */
    public function schools(): array
    {
        $sql = "SELECT s.school_id, s.name, s.ps_school_id, s.ad_ou, s.google_ou, s.status,
                       MAX(CASE WHEN a.system = 'nextgen' THEN a.code END)     AS nextgen_code,
                       MAX(CASE WHEN a.system = 'powerschool' THEN a.code END) AS powerschool_code
                FROM school s
                LEFT JOIN school_code_alias a ON a.school_id = s.school_id
                GROUP BY s.school_id, s.name, s.ps_school_id, s.ad_ou, s.google_ou, s.status
                ORDER BY s.name";
        $rows = $this->db->query($sql)->fetchAll();
        foreach ($rows as &$r) {
            $r['mapped'] = ($r['ad_ou'] ?? '') !== '' && ($r['google_ou'] ?? '') !== '';
        }
        return $rows;
    }

    /** Ethnicity source→ALSDE map. */
    public function ethnicityMap(): array
    {
        return $this->db->query(
            'SELECT source_value, alsde_code, federal_group FROM ethnicity_map ORDER BY alsde_code, source_value'
        )->fetchAll();
    }

    /** Distinct ethnicity values present on records but not mapped to a code. */
    public function unmappedEthnicity(): array
    {
        return $this->db->query(
            "SELECT ethnicity_source AS value, COUNT(*) AS n
             FROM person
             WHERE ethnicity_source IS NOT NULL AND ethnicity_source <> '' AND (ethnicity_code IS NULL OR ethnicity_code = '')
             GROUP BY ethnicity_source ORDER BY n DESC, ethnicity_source"
        )->fetchAll();
    }

    /** Job code -> person type map (classifies imported employees as faculty/staff). */
    public function positionMap(): array
    {
        return $this->db->query(
            "SELECT job_code, person_type, description
             FROM position_type_map
             ORDER BY FIELD(person_type, 'faculty','staff','contractor','sub','intern','other'), job_code"
        )->fetchAll();
    }

    /**
     * Distinct job codes on assignments with no position mapping. Informational,
     * not necessarily wrong — the map may be partial by design (list the faculty
     * codes; unmapped codes default to 'staff' on import).
     */
    public function unmappedJobCodes(): array
    {
        return $this->db->query(
            "SELECT a.job_code AS code, MAX(a.title) AS title, COUNT(DISTINCT a.person_id) AS n
             FROM assignment a
             WHERE a.job_code IS NOT NULL AND a.job_code <> ''
               AND NOT EXISTS (
                   SELECT 1 FROM position_type_map m
                   WHERE LOWER(TRIM(m.job_code)) = LOWER(TRIM(a.job_code))
               )
             GROUP BY a.job_code ORDER BY n DESC, a.job_code"
        )->fetchAll();
    }

    /** Distinct school codes seen in staged feeds with no alias mapping. */
    public function unmappedSchoolCodes(): array
    {
        // Leading-zero tolerant, matching the Normalizer: a feed code "0075"
        // counts as mapped if an alias "75" exists (and vice-versa).
        return $this->db->query(
            "SELECT s.system, s.n_school_code AS code, COUNT(*) AS n
             FROM staging_record s
             WHERE s.n_school_code IS NOT NULL AND s.n_school_code <> ''
               AND NOT EXISTS (
                   SELECT 1 FROM school_code_alias a
                   WHERE a.system = s.system
                     AND TRIM(LEADING '0' FROM a.code) = TRIM(LEADING '0' FROM s.n_school_code)
               )
             GROUP BY s.system, s.n_school_code ORDER BY n DESC, s.system, s.n_school_code"
        )->fetchAll();
    }
}
