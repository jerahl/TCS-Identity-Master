-- ============================================================================
-- 0008 — Students passthrough to OneSync
-- ----------------------------------------------------------------------------
-- Students are NOT part of the staff "golden record" identity model (no
-- matching, no crosswalk, no review queue). They are a straight passthrough:
-- we pull active enrollments from PowerSchool over ODBC and stage them in their
-- OWN table so OneSync can read them from one read-only view, exactly the way it
-- reads v_onesync_source for staff. The web app only shows the status of the
-- sync (last run, row counts, freshness) — there is no per-student editing.
--
-- Source query (PowerSchool Oracle, read-only):
--   SELECT State_StudentNumber, SchoolID, Grade_Level, First_Name, Last_Name,
--          ID, DCID, EntryCode, ExitCode, ExitDate
--   FROM Students WHERE Enroll_Status = 0 OR Enroll_Status = 3
-- (0 = currently enrolled, 3 = future enrollment.)
-- ============================================================================

SET NAMES utf8mb4;

-- One row per PowerSchool student enrollment record. DCID is PowerSchool's
-- stable internal key, so it's our upsert key. student_uuid is a surrogate we
-- mint once (stable external key = OneSync uniqueId), mirroring person_uuid.
-- Nothing here is destructive: a student that drops out of the pull is flagged
-- is_active = 0 (so OneSync can disable, not orphan), never deleted.
CREATE TABLE student (
  student_id           BIGINT       NOT NULL AUTO_INCREMENT,
  student_uuid         CHAR(36)     NOT NULL,            -- stable external key = OneSync uniqueId
  ps_dcid              VARCHAR(40)  NOT NULL,            -- Students.DCID (upsert key)
  ps_id                VARCHAR(40)  NULL,                -- Students.ID
  state_studentnumber  VARCHAR(40)  NULL,               -- Students.State_StudentNumber
  ps_school_id         VARCHAR(20)  NULL,               -- Students.SchoolID (PowerSchool building id)
  grade_level          VARCHAR(10)  NULL,               -- Students.Grade_Level
  first_name           VARCHAR(80)  NOT NULL,
  last_name            VARCHAR(80)  NOT NULL,
  entry_code           VARCHAR(20)  NULL,               -- Students.EntryCode
  exit_code            VARCHAR(20)  NULL,               -- Students.ExitCode
  exit_date            DATE         NULL,               -- Students.ExitDate
  enroll_status        VARCHAR(10)  NULL,               -- Students.Enroll_Status (0 or 3)
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 = dropped out of the latest pull
  first_seen           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  UNIQUE KEY uq_student_uuid (student_uuid),
  UNIQUE KEY uq_student_dcid (ps_dcid),
  KEY ix_student_active (is_active),
  KEY ix_student_school (ps_school_id),
  KEY ix_student_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per student sync run. The dashboard reads the latest row to show the
-- status of the students sync (when it ran, how many rows, did it succeed) —
-- the student equivalent of import_batch, kept separate because students never
-- touch the staging/matching pipeline.
CREATE TABLE student_import_batch (
  batch_id     BIGINT      NOT NULL AUTO_INCREMENT,
  source       VARCHAR(40) NOT NULL DEFAULT 'powerschool_odbc',
  started_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at  DATETIME    NULL,
  row_count    INT         NULL,                         -- rows read from PowerSchool
  inserted     INT         NOT NULL DEFAULT 0,
  updated      INT         NOT NULL DEFAULT 0,
  deactivated  INT         NOT NULL DEFAULT 0,           -- present before, gone now
  status       ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
  message      VARCHAR(500) NULL,
  PRIMARY KEY (batch_id),
  KEY ix_student_batch_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The SINGLE source OneSync reads for students (read-only DB user, same pattern
-- as v_onesync_source). uniqueId = student_uuid (stable). Inactive students are
-- kept and exposed with StatusActive = 0 so OneSync disables rather than orphans.
CREATE OR REPLACE VIEW v_onesync_student_source AS
SELECT
  s.student_uuid        AS uniqueId,
  s.state_studentnumber AS State_StudentNumber,
  s.ps_school_id        AS SchoolID,
  s.grade_level         AS Grade_Level,
  s.first_name          AS First_Name,
  s.last_name           AS Last_Name,
  s.ps_id               AS ID,
  s.ps_dcid             AS DCID,
  s.entry_code          AS EntryCode,
  s.exit_code           AS ExitCode,
  s.exit_date           AS ExitDate,
  s.enroll_status       AS Enroll_Status,
  s.is_active           AS StatusActive
FROM student s;
