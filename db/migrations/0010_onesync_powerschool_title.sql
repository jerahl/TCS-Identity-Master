-- ============================================================================
-- 0010 — expose the PowerSchool title on v_onesync_source
-- ----------------------------------------------------------------------------
-- OneSync wants BOTH titles as distinct columns:
--   `Job Code Desc` = the golden (NextGen) title, FALLING BACK to the PowerSchool
--                     title when the NextGen value is blank/NULL.
--   `Title`         = the PowerSchool title.
--
-- The golden record only stores one title (assignment.title, NextGen-driven), so
-- until now `Title` was just a second copy of `Job Code Desc`. The PowerSchool
-- title isn't on the golden record — it's captured on each PowerSchool import for
-- the NextGen↔PowerSchool reconciliation panel, in
-- staging_record.raw_json -> $.fields.title. Pull the latest one per person, and
-- also use it as the `Job Code Desc` fallback so OneSync never gets a blank title
-- when only PowerSchool has one. (The stored golden record is unchanged — this is
-- a read-time fallback in the view, so PowerSchool still isn't written to golden.)
--
-- Index staging_record.matched_person_id first so the correlated lookup is cheap
-- (the table only had batch/status indexes before).
-- ============================================================================

CREATE INDEX ix_stage_matched ON staging_record (matched_person_id);

CREATE OR REPLACE VIEW v_onesync_source AS
SELECT
  p.person_uuid                         AS ID,
  (SELECT psi.source_key
     FROM person_source_id psi
    WHERE psi.person_id = p.person_id
      AND psi.system    = 'powerschool'
      AND psi.is_active = 1
    ORDER BY psi.last_seen DESC, psi.id DESC
    LIMIT 1)                            AS PSID,
  COALESCE(
    NULLIF((SELECT a.title
              FROM assignment a
             WHERE a.person_id  = p.person_id
               AND a.is_primary = 1
             ORDER BY a.id
             LIMIT 1), ''),
    (SELECT JSON_UNQUOTE(JSON_EXTRACT(sr.raw_json, '$.fields.title'))
       FROM staging_record sr
      WHERE sr.matched_person_id = p.person_id
        AND sr.system = 'powerschool'
      ORDER BY sr.id DESC
      LIMIT 1)
  )                                     AS `Job Code Desc`,   -- NextGen title, PowerSchool fallback
  s.ps_school_id                        AS HomeSchoolID,
  p.employee_id                         AS TeacherNumber,
  p.employee_id                         AS EmployeeID,
  p.email                               AS Email,
  p.username                            AS username,          -- NULL until minted
  (SELECT JSON_UNQUOTE(JSON_EXTRACT(sr.raw_json, '$.fields.title'))
     FROM staging_record sr
    WHERE sr.matched_person_id = p.person_id
      AND sr.system = 'powerschool'
    ORDER BY sr.id DESC
    LIMIT 1)                            AS Title,   -- PowerSchool title (latest import snapshot)
  p.first_name                          AS FirstName,
  p.last_name                           AS LastName,
  CASE WHEN p.status IN ('active','pending') THEN 1 ELSE 0 END AS StatusActive,
  p.ethnicity_code                      AS Ethnicity
FROM person p
LEFT JOIN school s ON s.school_id = p.primary_school_id
WHERE p.status IN ('active','pending','disabled');  -- disabled kept so OneSync can disable, not orphan
