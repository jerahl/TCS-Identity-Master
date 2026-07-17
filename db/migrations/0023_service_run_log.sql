-- ============================================================================
-- 0023 — per-item service run log (Outputs "view log" pages)
-- ----------------------------------------------------------------------------
-- One row per noteworthy person/row a background job touched, keyed to its
-- service_run row. service_run alone stores only rolled-up counts and a
-- one-line message — the per-person detail the CLIs print (which account was
-- created / pushed / expired, which errored and why) was lost once the
-- terminal scrolled. This table persists that detail so the web console can
-- show a full log for every sync run, filterable by severity.
--
-- `level` classifies each entry for the Outputs-page tiles:
--   'attention'  errors, needs-review, guardrail-blocked, license-blocked —
--                everything the "requires attention" tile counts
--   'change'     a change actually applied (created / pushed / expired /
--                exported rows / group syncs)
--   'info'       context (writes-off previews, capped, manual overrides)
--
-- Written by the CLIs (bin/adaxes_sync.php, bin/sync_google.php,
-- bin/export_powerschool.php) through ServiceRunLog, same app-role connection
-- as service_run. The app role's database-wide SELECT/INSERT/UPDATE grant
-- already covers it — no new GRANTs needed.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE service_run_log (
  log_id     BIGINT       NOT NULL AUTO_INCREMENT,
  run_id     BIGINT       NOT NULL,                  -- service_run.run_id
  seq        INT          NOT NULL DEFAULT 0,        -- order within the run
  logged_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  phase      VARCHAR(24)  NULL,                      -- adaxes phase / 'scan' / 'apply' / 'export'
  person_id  BIGINT       NULL,                      -- golden-record person, when known
  subject    VARCHAR(190) NOT NULL DEFAULT '',       -- person name or account email
  outcome    VARCHAR(32)  NOT NULL,                  -- 'created' | 'error' | 'exception' | ...
  level      ENUM('info','change','attention') NOT NULL DEFAULT 'info',
  detail     VARCHAR(1000) NOT NULL DEFAULT '',
  PRIMARY KEY (log_id),
  KEY ix_srl_run (run_id, seq),
  KEY ix_srl_run_level (run_id, level, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
