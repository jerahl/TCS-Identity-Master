-- ============================================================================
-- 0009 — reshape the OneSync faculty source view (v_onesync_source)
-- ----------------------------------------------------------------------------
-- OneSync's faculty profile reads a fixed column set. Redefine v_onesync_source
-- to expose exactly what OneSync consumes, under OneSync's own column names:
--
--   ID, PSID, `Job Code Desc`, HomeSchoolID, TeacherNumber, EmployeeID,
--   Email, username, Title, FirstName, LastName, StatusActive, Ethnicity
--
-- Mapping to the golden record:
--   ID            = person.person_uuid          (the stable uniqueId)
--   PSID          = active PowerSchool crosswalk id (person_source_id, system='powerschool')
--   `Job Code Desc` / Title = primary assignment.title (one golden field; OneSync
--                   wants it under both names)
--   HomeSchoolID  = primary school's PowerSchool SchoolID (school.ps_school_id)
--   TeacherNumber / EmployeeID = person.employee_id (one golden field; OneSync
--                   wants it under both names; NULL for subs/contractors/interns)
--   Email         = person.email                 (NULL until assigned)
--   username      = person.username              (NULL until OneSync mints it)
--   FirstName     = person.first_name
--   LastName      = person.last_name
--   StatusActive  = 1 when status in (active, pending), else 0 (so OneSync can
--                   disable, not orphan, disabled people)
--   Ethnicity     = person.ethnicity_code        (resolved ALSDE code)
--
-- Row scope is unchanged: active, pending and disabled people are returned (the
-- only object OneSync should read; still one row per person). The previous
-- PersonType / PreferredName columns are no longer exposed.
-- ============================================================================

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
  (SELECT a.title
     FROM assignment a
    WHERE a.person_id  = p.person_id
      AND a.is_primary = 1
    ORDER BY a.id
    LIMIT 1)                            AS `Job Code Desc`,
  s.ps_school_id                        AS HomeSchoolID,
  p.employee_id                         AS TeacherNumber,
  p.employee_id                         AS EmployeeID,
  p.email                               AS Email,
  p.username                            AS username,          -- NULL until minted
  (SELECT a.title
     FROM assignment a
    WHERE a.person_id  = p.person_id
      AND a.is_primary = 1
    ORDER BY a.id
    LIMIT 1)                            AS Title,
  p.first_name                          AS FirstName,
  p.last_name                           AS LastName,
  CASE WHEN p.status IN ('active','pending') THEN 1 ELSE 0 END AS StatusActive,
  p.ethnicity_code                      AS Ethnicity
FROM person p
LEFT JOIN school s ON s.school_id = p.primary_school_id
WHERE p.status IN ('active','pending','disabled');  -- disabled kept so OneSync can disable, not orphan
