-- ============================================================================
-- 0021 — Per-person Raptor role override (group exceptions)
-- The Phase-4 group policy assigns exactly one Raptor role from the job title
-- (BuildingAdmin / ClientAdmin / EntryAdmin / GlobalAdmin / EmergencyManagement).
-- Some people need a manual exception — e.g. grant Raptor_ClientAdmin to a user
-- whose title wouldn't earn it. This column holds that override.
--
-- Notes:
--   * Stores a STABLE role KEY (not the AD group name): '' / NULL = automatic
--     (by title), 'none' = exclude from every Raptor group, else one of
--     'buildingadmin' | 'clientadmin' | 'entryadmin' | 'globaladmin' |
--     'emergency' (see App\Service\GroupPolicy::raptorRoleOptions()). Keying by
--     role survives an AD group-name change in config.
--   * NULL default = every existing person keeps the automatic (title) behavior.
-- ============================================================================

ALTER TABLE person
  ADD COLUMN raptor_group_override VARCHAR(40) NULL AFTER username_locked;
