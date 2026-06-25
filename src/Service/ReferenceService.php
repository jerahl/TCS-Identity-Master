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

    /** Distinct school codes seen in staged feeds with no alias mapping. */
    public function unmappedSchoolCodes(): array
    {
        return $this->db->query(
            "SELECT s.system, s.n_school_code AS code, COUNT(*) AS n
             FROM staging_record s
             WHERE s.n_school_code IS NOT NULL AND s.n_school_code <> ''
               AND NOT EXISTS (SELECT 1 FROM school_code_alias a WHERE a.system = s.system AND a.code = s.n_school_code)
             GROUP BY s.system, s.n_school_code ORDER BY n DESC, s.system, s.n_school_code"
        )->fetchAll();
    }
}
