-- ============================================================================
-- 0007 — store the full NextGen HR field set on the golden record
-- The NextGen ITExtract feed carries contact + position fields we previously
-- dropped (only ~10 of its columns were promoted). Add them so the person record
-- shows every NextGen field and can be mapped to PowerSchool for the same user.
--
-- Notes:
--   * `hr_email` is the HR (NextGen) e-mail address. It is DISTINCT from
--     person.email, which is minted + locked by OneSync — importers never touch
--     that one. This column is informational (the HR-of-record address).
--   * `dob` and `alsde_id` already exist (0001); they are populated from
--     PowerSchool (DOB + Alabama State ID), not NextGen.
--   * phone / address1 / address2 are SENSITIVE PII — treated like the other
--     demographics (shown only on the read-only person record, never synced).
-- ============================================================================

ALTER TABLE person
  ADD COLUMN hr_email            VARCHAR(160) NULL AFTER alsde_id,
  ADD COLUMN position_number     VARCHAR(40)  NULL AFTER hr_email,
  ADD COLUMN cctr_description    VARCHAR(120) NULL AFTER position_number,
  ADD COLUMN position_start_date DATE         NULL AFTER cctr_description,
  ADD COLUMN phone               VARCHAR(40)  NULL AFTER position_start_date,
  ADD COLUMN address1            VARCHAR(160) NULL AFTER phone,
  ADD COLUMN address2            VARCHAR(160) NULL AFTER address1,
  ADD COLUMN city                VARCHAR(80)  NULL AFTER address2,
  ADD COLUMN state_code          VARCHAR(10)  NULL AFTER city,
  ADD COLUMN zip_code            VARCHAR(20)  NULL AFTER state_code;
