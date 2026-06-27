-- ============================================================================
-- 0002 — auth/login auditing
-- Extend audit_log so logins/logouts and app_user role changes are auditable in
-- the same place as every other mutation (Milestone 7).
-- ============================================================================

ALTER TABLE audit_log
  MODIFY entity ENUM('person','assignment','source_id','match','school','config','user') NOT NULL;

ALTER TABLE audit_log
  MODIFY action ENUM('insert','update','delete','merge','login','logout') NOT NULL;
