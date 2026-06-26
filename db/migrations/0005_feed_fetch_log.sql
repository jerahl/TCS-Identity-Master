-- ============================================================================
-- 0005 — SFTP feed fetch log
-- Tracks CSVs pulled from the SFTP server so re-runs don't re-download / re-import
-- the same file (dedupe by (system, remote_name)). Links to the import_batch the
-- file produced.
-- ============================================================================

CREATE TABLE feed_fetch_log (
  id           BIGINT       NOT NULL AUTO_INCREMENT,
  system       ENUM('nextgen','powerschool','intern','sub','contractor','manual') NOT NULL,
  remote_name  VARCHAR(260) NOT NULL,
  local_path   VARCHAR(512) NULL,
  size_bytes   BIGINT       NULL,
  status       ENUM('downloaded','imported','failed') NOT NULL DEFAULT 'downloaded',
  message      VARCHAR(500) NULL,
  batch_id     BIGINT       NULL,
  fetched_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fetch (system, remote_name),
  KEY ix_fetch_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
