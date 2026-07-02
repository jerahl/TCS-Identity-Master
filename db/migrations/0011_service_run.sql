-- ============================================================================
-- 0011 — service run log (admin "Services" page)
-- ----------------------------------------------------------------------------
-- One row per run of a background job, from either the CLI/cron or the web
-- admin "Services" page. It gives an authoritative "last run" for jobs that
-- otherwise leave no run record of their own — most importantly the OneSync DB
-- result sync (bin/import_onesync_db.php), which writes account_sync_status but
-- has never recorded WHEN it ran or with what outcome.
--
-- The feed imports and the students sync already have per-run tables
-- (import_batch, student_import_batch); this table complements those with a
-- unified, job-keyed history so the admin page can show the last run of every
-- service in one place and record manual "Run now" actions with their result.
--
-- Written by the APP role (web admin actions) and by the CLI (which opens an
-- app-role connection just for run logging). Grant the app user
-- INSERT/UPDATE/SELECT on service_run.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE service_run (
  run_id      BIGINT       NOT NULL AUTO_INCREMENT,
  job         VARCHAR(40)  NOT NULL,                 -- 'onesync_db' | 'feeds' | 'students'
  origin      VARCHAR(20)  NOT NULL DEFAULT 'manual',-- 'manual' (web) | 'cron' (CLI)
  started_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME     NULL,
  status      ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
  actor       VARCHAR(60)  NULL,                     -- who/what triggered it
  counts_json JSON         NULL,                     -- per-job result counts
  message     VARCHAR(1000) NULL,                    -- one-line summary or error
  PRIMARY KEY (run_id),
  KEY ix_service_run_job (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
