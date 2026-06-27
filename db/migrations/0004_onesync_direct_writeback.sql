-- ============================================================================
-- 0004 — support OneSync writing directly to the DB
-- When OneSync upserts account_sync_status it only knows uniqueId (person_uuid),
-- not our surrogate person_id. These triggers resolve person_id from person_uuid
-- so the rows immediately link to the golden record (person detail + dashboard
-- failed-sync rollup query by person_id). Triggers run as definer, so the
-- OneSync writer needs no SELECT on `person`.
-- ============================================================================

DROP TRIGGER IF EXISTS trg_acct_status_resolve_bi;
CREATE TRIGGER trg_acct_status_resolve_bi BEFORE INSERT ON account_sync_status
  FOR EACH ROW SET NEW.person_id = COALESCE(NEW.person_id, (SELECT p.person_id FROM person p WHERE p.person_uuid = NEW.person_uuid));

DROP TRIGGER IF EXISTS trg_acct_status_resolve_bu;
CREATE TRIGGER trg_acct_status_resolve_bu BEFORE UPDATE ON account_sync_status
  FOR EACH ROW SET NEW.person_id = COALESCE(NEW.person_id, (SELECT p.person_id FROM person p WHERE p.person_uuid = NEW.person_uuid));
