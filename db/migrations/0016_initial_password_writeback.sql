-- ============================================================================
-- 0016 — Initial-password write-back (OneSync)
-- OneSync sets a temporary password when it creates an account and reports it
-- to POST /api/onesync/password. Give it a home on the golden record so the
-- orientation workflow can hand it to the new hire.
--
-- Notes:
--   * NEVER plaintext: the API encrypts with libsodium secretbox under
--     CREDENTIAL_ENC_KEY (app env) before the UPDATE — the database only ever
--     sees nonce||ciphertext. A DB dump without the app key is useless.
--   * VARBINARY(512) fits the 24-byte nonce + 16-byte MAC + a password of any
--     realistic length.
--   * lifecycle_event gains 'password_received' so the person timeline shows
--     when OneSync delivered/replaced the password (never the value).
-- ============================================================================

ALTER TABLE person
  ADD COLUMN initial_password_enc    VARBINARY(512) NULL AFTER username_locked,
  ADD COLUMN initial_password_set_at DATETIME       NULL AFTER initial_password_enc;

ALTER TABLE lifecycle_event
  MODIFY COLUMN event_type ENUM('create','update','disable','enable','terminate','convert','merge','username_assigned','notify','password_received') NOT NULL;
