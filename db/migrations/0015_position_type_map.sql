-- ============================================================================
-- 0015 — position_type_map: classify imported employees by job code
-- ----------------------------------------------------------------------------
-- The NextGen HR feed carries no person-type column, so every employee it
-- creates lands as 'staff' (the PersonWriter fallback). This table maps the
-- feed's JOB CODE to a person_type so teachers/counselors/etc. come in as
-- 'faculty' instead. Applied by the Normalizer when a feed provides a job code
-- but no explicit type; job codes are matched case-insensitively.
--
-- The map may be PARTIAL by design: list the faculty codes and let everything
-- else default to 'staff'. Unmapped codes are surfaced on the Reference page
-- (Positions tab) so an operator can extend the map. Seeded from
-- db/seeds/position_type_map.csv via bin/seed.php.
-- ============================================================================

CREATE TABLE position_type_map (
  job_code     VARCHAR(40)  NOT NULL,               -- NextGen JOB CODE
  person_type  ENUM('faculty','staff','contractor','sub','intern','other') NOT NULL,
  description  VARCHAR(120) NULL,                   -- human label (e.g. Job Code Desc)
  PRIMARY KEY (job_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
