-- ============================================================================
-- 0006 — track the remote file modification time on the fetch log
-- The district overwrites each feed CSV in place (same name) on every update, so
-- de-duping by (system, remote_name) alone would skip genuine updates. Record the
-- remote mtime so the fetcher re-downloads when the file is newer than last seen.
-- ============================================================================

ALTER TABLE feed_fetch_log
  ADD COLUMN remote_mtime BIGINT NULL AFTER size_bytes;
