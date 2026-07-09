-- ============================================================================
-- 0017 — app_setting: admin-editable configuration overrides
-- ----------------------------------------------------------------------------
-- A small key/value store for the operationally-tunable configuration an admin
-- can change from the web console (Settings → Configuration) WITHOUT editing
-- .env and redeploying — the Adaxes direct-provisioning knobs, OU/placement,
-- username minting, and AD group names.
--
-- These layer UNDER real environment variables (a container/host env var always
-- wins) and OVER the .env file, so a value set here takes effect for both the
-- web app and the CLI reconciler (loaded at bootstrap via SettingsService).
--
-- Only a curated WHITELIST of non-secret keys is writable (see SettingsService).
-- Secrets — tokens, passwords, DB/SAML/Google credentials — are deliberately
-- NOT stored here; they stay in .env / the environment.
-- ============================================================================

CREATE TABLE app_setting (
  setting_key   VARCHAR(80)  NOT NULL,              -- config key, e.g. AD_BASE_DN
  setting_value TEXT         NULL,                  -- stored as text; typed on read
  updated_by    VARCHAR(120) NULL,                  -- actor (SAML user / job)
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
