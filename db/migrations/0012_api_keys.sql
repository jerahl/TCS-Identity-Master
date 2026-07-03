-- ============================================================================
-- 0012 — per-user API keys (MCP / programmatic access)
-- ----------------------------------------------------------------------------
-- Each key belongs to exactly one app_user. Access granted by a key is bounded
-- by that user's live role (readonly/editor/admin) — resolved at request time,
-- so revoking or downgrading the user immediately narrows what the key can do.
--
-- Only a SHA-256 hash of the secret is stored; the raw key is shown to the user
-- once at creation and never persisted. A short, non-secret `token_prefix`
-- (e.g. "tcsidm_ab12cd") is kept so a key can be recognised in the UI/CLI.
-- Keys are revoked (revoked_at set), never hard-deleted, so audit history holds.
-- ============================================================================

CREATE TABLE api_key (
  id            BIGINT       NOT NULL AUTO_INCREMENT,
  user_id       BIGINT       NOT NULL,               -- owning app_user
  label         VARCHAR(120) NOT NULL,               -- human name ("Claude Desktop")
  token_prefix  VARCHAR(16)  NOT NULL,               -- non-secret display hint
  token_hash    CHAR(64)     NOT NULL,               -- sha256(hex) of the full key
  last_used_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by    VARCHAR(160) NULL,                   -- actor who minted it
  revoked_at    DATETIME     NULL,                   -- NULL = active
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_key_hash (token_hash),
  KEY ix_api_key_user (user_id),
  KEY ix_api_key_active (user_id, revoked_at),
  CONSTRAINT fk_api_key_user FOREIGN KEY (user_id)
    REFERENCES app_user(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
