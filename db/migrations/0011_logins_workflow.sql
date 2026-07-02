-- ============================================================================
-- 0011 — Board approval fields for the Logins export workflow
-- The Logins spreadsheet carries a "Board Approval" column that no feed
-- (NextGen / PowerSchool) provides — it's recorded by hand from the board
-- agenda. Give it a home on the golden record so it can be entered in-app
-- (audited) and included in the Logins export instead of a spreadsheet cell.
--
-- Notes:
--   * board_approval_date is the value the Logins column actually wants (the
--     date the board approved the hire/transfer). Nullable — old rows and feed
--     imports simply leave it blank; importers never touch these columns.
--   * board_approval_note is optional free text (agenda item, "pending", etc.).
--   * The Logins "From School / From Position" transfer context needs no new
--     columns — it's derived from the person's prior `assignment` row.
--   * ALSDE ID needs no new column — person.alsde_id already exists (0001),
--     populated from PowerSchool.
-- ============================================================================

ALTER TABLE person
  ADD COLUMN board_approval_date DATE         NULL AFTER end_date,
  ADD COLUMN board_approval_note VARCHAR(120) NULL AFTER board_approval_date;
