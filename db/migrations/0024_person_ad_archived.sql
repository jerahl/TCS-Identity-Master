-- ============================================================================
-- 0024 — archive fully-processed AD leavers (skip them in the Adaxes sync)
-- ----------------------------------------------------------------------------
-- The Adaxes disable phase re-verifies EVERY golden-disabled person against
-- live AD on every run — one remote lookup each — so the nightly run grows
-- without bound as leavers accumulate. But a leaver's AD lifecycle has a
-- terminal state: IDM expires the account, then Adaxes' own scheduled task
-- moves expired users into the disabled OU after its holding period. Once an
-- account is BOTH expired and in the disabled OU (AD_DISABLED_OU), there is
-- nothing left for the sync to do or watch.
--
-- ad_archived_at stamps that moment. The disable phase excludes archived
-- people from its scan, and clears the stamp for anyone whose golden status
-- returns to active/pending — a re-hire re-enters normal sync coverage
-- automatically (the create phase's returning-employee path re-enables the
-- account as before).
--
-- Written by bin/adaxes_sync.php through the app role; covered by the app
-- user's database-wide grants. NULL = in normal sync coverage.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE person
  ADD COLUMN ad_archived_at DATETIME NULL AFTER username_locked;
