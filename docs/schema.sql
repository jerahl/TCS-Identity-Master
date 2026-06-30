-- ============================================================================
-- TCS Identity Master — MySQL schema (draft v0.1)
-- ----------------------------------------------------------------------------
-- Purpose: one "golden record" per human, with a crosswalk to every system ID
-- they carry, so OneSync can read ONE source and stop creating a separate user
-- per source. This DB is the single source of truth for staff identity; OneSync
-- is the provisioning engine that mints the username and writes it back here.
--
-- Conventions: MySQL 8+, InnoDB, utf8mb4. IDs are surrogate BIGINTs; the stable
-- external key shared with OneSync is person.person_uuid. Nothing here is
-- destructive — status changes + audit rows instead of deletes.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- Reference / configuration
-- ----------------------------------------------------------------------------

-- One row per real school/building. Holds the cross-system codes so a single
-- place resolves "NextGen home-school code -> PowerSchool SchoolID / AD OU /
-- Google OU" (kills the everyone-lands-at-school-0 problem).
CREATE TABLE school (
  school_id        INT             NOT NULL AUTO_INCREMENT,
  name             VARCHAR(120)    NOT NULL,
  ps_school_id     VARCHAR(20)     NULL,            -- PowerSchool SchoolID
  ad_ou            VARCHAR(400)    NULL,            -- AD OU distinguishedName
  google_ou        VARCHAR(400)    NULL,            -- Google Workspace OU path
  status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (school_id),
  UNIQUE KEY uq_school_ps (ps_school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maps the various source codes for a building to the canonical school row.
-- e.g. NextGen home-school code '8620' -> school_id 1. Multiple aliases per school.
CREATE TABLE school_code_alias (
  alias_id   INT          NOT NULL AUTO_INCREMENT,
  school_id  INT          NOT NULL,
  system     ENUM('nextgen','powerschool','other') NOT NULL,
  code       VARCHAR(40)  NOT NULL,
  PRIMARY KEY (alias_id),
  UNIQUE KEY uq_alias (system, code),
  CONSTRAINT fk_alias_school FOREIGN KEY (school_id) REFERENCES school(school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Race/ethnicity source value -> PowerSchool/ALSDE code (replaces the partial
-- Black->B / White->C map in the old script; unmapped values are surfaced).
CREATE TABLE ethnicity_map (
  source_value   VARCHAR(60)  NOT NULL,
  alsde_code     VARCHAR(10)  NOT NULL,
  federal_group  VARCHAR(60)  NULL,
  PRIMARY KEY (source_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Golden record
-- ----------------------------------------------------------------------------

CREATE TABLE person (
  person_id        BIGINT       NOT NULL AUTO_INCREMENT,
  person_uuid      CHAR(36)     NOT NULL,            -- stable external key = OneSync uniqueId
  person_type      ENUM('faculty','staff','contractor','sub','intern','other') NOT NULL DEFAULT 'staff',
  status           ENUM('pending','active','disabled','terminated') NOT NULL DEFAULT 'pending',

  -- name / demographics (HR-sourced)
  first_name       VARCHAR(80)  NOT NULL,
  middle_name      VARCHAR(80)  NULL,
  last_name        VARCHAR(80)  NOT NULL,
  preferred_name   VARCHAR(80)  NULL,
  dob              DATE         NULL,
  gender           VARCHAR(20)  NULL,
  ethnicity_source VARCHAR(60)  NULL,                -- raw value as received
  ethnicity_code   VARCHAR(10)  NULL,                -- resolved ALSDE/PS code
  alsde_id         VARCHAR(30)  NULL,

  -- canonical HR id (NextGen). Nullable: subs/contractors won't have one.
  employee_id      VARCHAR(40)  NULL,

  -- assignment summary (detail in `assignment`)
  primary_school_id INT         NULL,
  hire_date        DATE         NULL,
  end_date         DATE         NULL,

  -- assigned identity: minted by OneSync, written back here. Immutable once set.
  username         VARCHAR(64)  NULL,
  email            VARCHAR(160) NULL,
  upn              VARCHAR(160) NULL,
  username_assigned_at DATETIME NULL,
  username_locked  TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = never re-mint/rename

  source_of_record ENUM('nextgen','manual','powerschool') NOT NULL DEFAULT 'nextgen',
  notes            VARCHAR(500) NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by       VARCHAR(60)  NULL,
  updated_by       VARCHAR(60)  NULL,

  PRIMARY KEY (person_id),
  UNIQUE KEY uq_person_uuid (person_uuid),
  UNIQUE KEY uq_person_username (username),          -- enforce username uniqueness centrally
  UNIQUE KEY uq_person_email (email),
  KEY ix_person_employee (employee_id),
  KEY ix_person_name (last_name, first_name),
  CONSTRAINT fk_person_primary_school FOREIGN KEY (primary_school_id) REFERENCES school(school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The crosswalk that makes dedup possible: many system IDs -> one person.
-- A hired intern keeps their person row; we just add their new PowerSchool/AD
-- source IDs here. UNIQUE(system, source_key) stops one source ID mapping twice.
CREATE TABLE person_source_id (
  id          BIGINT      NOT NULL AUTO_INCREMENT,
  person_id   BIGINT      NOT NULL,
  system      ENUM('nextgen','powerschool','ad','google','intern_csv','alsde','onesync','manual') NOT NULL,
  source_key  VARCHAR(128) NOT NULL,                 -- TeacherNumber, AD objectGUID, etc.
  is_active   TINYINT(1)  NOT NULL DEFAULT 1,
  first_seen  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_system_key (system, source_key),
  KEY ix_psid_person (person_id),
  CONSTRAINT fk_psid_person FOREIGN KEY (person_id) REFERENCES person(person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Multi-location: one person can have several assignments; exactly one primary.
-- Resolves the "two locations, no primary flag -> skipped" problem.
CREATE TABLE assignment (
  id             BIGINT     NOT NULL AUTO_INCREMENT,
  person_id      BIGINT     NOT NULL,
  school_id      INT        NOT NULL,
  title          VARCHAR(120) NULL,
  job_code       VARCHAR(40)  NULL,
  fte            DECIMAL(4,2) NULL,
  is_primary     TINYINT(1) NOT NULL DEFAULT 0,
  effective_date DATE       NULL,
  end_date       DATE       NULL,
  source         ENUM('nextgen','powerschool','manual') NOT NULL DEFAULT 'nextgen',
  PRIMARY KEY (id),
  KEY ix_assign_person (person_id),
  CONSTRAINT fk_assign_person FOREIGN KEY (person_id) REFERENCES person(person_id),
  CONSTRAINT fk_assign_school FOREIGN KEY (school_id) REFERENCES school(school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Lifecycle + audit  (identity store is critical-path: log everything)
-- ----------------------------------------------------------------------------

CREATE TABLE lifecycle_event (
  id          BIGINT      NOT NULL AUTO_INCREMENT,
  person_id   BIGINT      NOT NULL,
  event_type  ENUM('create','update','disable','enable','terminate','convert','merge','username_assigned') NOT NULL,
  detail      JSON        NULL,
  occurred_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor       VARCHAR(60) NULL,                      -- user or 'system:<job>'
  PRIMARY KEY (id),
  KEY ix_life_person (person_id),
  CONSTRAINT fk_life_person FOREIGN KEY (person_id) REFERENCES person(person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id         BIGINT      NOT NULL AUTO_INCREMENT,
  entity     ENUM('person','assignment','source_id','match','school','config') NOT NULL,
  entity_id  BIGINT      NULL,
  action     ENUM('insert','update','delete','merge') NOT NULL,
  before_json JSON       NULL,
  after_json  JSON       NULL,
  actor      VARCHAR(60) NULL,
  at         DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Ingestion + matching (NextGen / PowerSchool feeds land here first)
-- ----------------------------------------------------------------------------

CREATE TABLE import_batch (
  batch_id    BIGINT      NOT NULL AUTO_INCREMENT,
  system      ENUM('nextgen','powerschool','manual') NOT NULL,
  file_name   VARCHAR(260) NULL,
  started_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME    NULL,
  row_count   INT         NULL,
  status      ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
  message     VARCHAR(500) NULL,
  PRIMARY KEY (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Raw + normalized incoming rows, with their match decision.
CREATE TABLE staging_record (
  id              BIGINT     NOT NULL AUTO_INCREMENT,
  batch_id        BIGINT     NOT NULL,
  system          ENUM('nextgen','powerschool','manual') NOT NULL,
  raw_json        JSON       NULL,                   -- the source row as received
  n_first         VARCHAR(80) NULL,
  n_last          VARCHAR(80) NULL,
  n_dob           DATE        NULL,
  n_employee_id   VARCHAR(40) NULL,
  n_source_key    VARCHAR(128) NULL,                 -- this system's id for the row
  n_school_code   VARCHAR(40) NULL,
  match_status    ENUM('auto_matched','new','needs_review','merged','skipped') NOT NULL DEFAULT 'new',
  matched_person_id BIGINT   NULL,
  reason          VARCHAR(300) NULL,
  loaded_at       DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_stage_batch (batch_id),
  KEY ix_stage_status (match_status),
  CONSTRAINT fk_stage_batch FOREIGN KEY (batch_id) REFERENCES import_batch(batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Human review queue for ambiguous matches (esp. the name-only intern->employee
-- case). The dashboard presents these as "are these the same person?" decisions.
CREATE TABLE match_candidate (
  id                 BIGINT     NOT NULL AUTO_INCREMENT,
  staging_id         BIGINT     NOT NULL,
  candidate_person_id BIGINT    NOT NULL,
  score              DECIMAL(5,2) NULL,              -- match confidence
  match_basis        VARCHAR(80) NULL,               -- 'employee_id' | 'name+dob' | 'name_only' ...
  status             ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  decided_by         VARCHAR(60) NULL,
  decided_at         DATETIME   NULL,
  PRIMARY KEY (id),
  KEY ix_mc_status (status),
  CONSTRAINT fk_mc_stage FOREIGN KEY (staging_id) REFERENCES staging_record(id),
  CONSTRAINT fk_mc_person FOREIGN KEY (candidate_person_id) REFERENCES person(person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- OneSync interface
-- ----------------------------------------------------------------------------

-- The SINGLE source OneSync reads (read-only DB user). One row per person ->
-- one OneSync user per person. ID = person_uuid (stable). Username starts NULL so
-- OneSync's BlankSAMAccountName rule mints it; thereafter it's set. Columns use
-- OneSync's faculty-profile names (see migration 0009).
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

-- Write-back landing table: OneSync emits its usernames file (same file the
-- 2 AM PowerSchool autocomm consumes); a small importer drops rows here and the
-- app applies them to person.username/email (locking the username).
CREATE TABLE onesync_writeback (
  id           BIGINT     NOT NULL AUTO_INCREMENT,
  person_uuid  CHAR(36)   NOT NULL,
  username     VARCHAR(64) NULL,
  email        VARCHAR(160) NULL,
  received_at  DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied      TINYINT(1) NOT NULL DEFAULT 0,
  applied_at   DATETIME   NULL,
  PRIMARY KEY (id),
  KEY ix_wb_uuid (person_uuid),
  KEY ix_wb_applied (applied)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-account provisioning status written back from OneSync. OneSync emits its
-- export log (username, uniqueId, action, actionStatus, destination, timestamps,
-- message); an importer upserts ONE current-status row per (person, destination)
-- so the dashboard can show "is this account provisioned in AD / Google / Raptor /
-- PowerSchool, and did the last sync succeed or fail?" (surfaces, per person, the
-- failures seen in the raw export logs).
CREATE TABLE account_sync_status (
  id            BIGINT       NOT NULL AUTO_INCREMENT,
  person_id     BIGINT       NULL,                   -- resolved from person_uuid
  person_uuid   CHAR(36)     NOT NULL,
  destination   VARCHAR(80)  NOT NULL,               -- 'Faculty AD','Google Faculty',...
  dest_type     VARCHAR(40)  NULL,                   -- 'ActiveDirectory','GSuite','CSV',...
  last_action   ENUM('Add','Edit','Disable','Enable','NoChange','New') NULL,
  last_status   ENUM('Success','Fail','Skipped','New') NULL,
  last_sync_at  DATETIME     NULL,
  message       VARCHAR(1000) NULL,                  -- last error/info from OneSync
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_acct_dest (person_uuid, destination),
  KEY ix_acct_person (person_id),
  KEY ix_acct_status (last_status),
  CONSTRAINT fk_acct_person FOREIGN KEY (person_id) REFERENCES person(person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional capped history of sync events (the current-status table above is the
-- primary surface). Rotate/prune to avoid the multi-million-row bloat seen in the
-- raw OneSync export logs.
CREATE TABLE account_sync_event (
  id           BIGINT       NOT NULL AUTO_INCREMENT,
  person_uuid  CHAR(36)     NOT NULL,
  destination  VARCHAR(80)  NOT NULL,
  action       VARCHAR(20)  NULL,
  status       VARCHAR(20)  NULL,
  message      VARCHAR(1000) NULL,
  occurred_at  DATETIME     NULL,
  imported_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_evt_uuid (person_uuid),
  KEY ix_evt_dest_time (destination, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Authentication (SAML SSO) + role-based access
-- ----------------------------------------------------------------------------
-- Identity comes from the district IdP via SAML; we store the mapping to a role.
-- Roles: admin (full + manage users + reference data + override decisions),
-- editor (edit people, work the review queue, manual add), readonly (view only).
CREATE TABLE app_user (
  user_id       BIGINT       NOT NULL AUTO_INCREMENT,
  saml_name_id  VARCHAR(255) NOT NULL,               -- IdP subject / NameID
  email         VARCHAR(160) NOT NULL,
  display_name  VARCHAR(160) NULL,
  role          ENUM('admin','editor','readonly') NOT NULL DEFAULT 'readonly',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_user_nameid (saml_name_id),
  UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Notes
--  * person.username UNIQUE + onesync_writeback application should set
--    username_locked=1 so the value is never re-minted or renamed.
--  * The match pipeline writes staging_record first; auto-matches on a strong
--    key (employee_id / existing source_key); anything weaker (name-only) goes
--    to match_candidate for human confirmation in the dashboard.
--  * Least-privilege DB users: OneSync = READ-ONLY on v_onesync_source only; the
--    app uses its own account; the username/status write-back importers use a
--    limited writer (insert/update on onesync_writeback, account_sync_status,
--    account_sync_event only). Never reuse the app or root account for OneSync.
--  * account_sync_status is upserted per (person_uuid, destination) from OneSync's
--    export log so the dashboard shows each account's current AD/Google/Raptor/PS state.
--  * Auth is SAML SSO; app_user maps the IdP NameID/email to a role. Seed the first
--    admin(s) manually; first-login users default to readonly pending an admin grant.
--  * CONFIRM before load: ethnicity_map codes, school + school_code_alias rows, the
--    exact OneSync source field names this view must expose, the SAML IdP details,
--    and the format/location of the OneSync export-log/status file.
-- ============================================================================
