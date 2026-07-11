-- ============================================================================
-- 0019 — email_outbox: a record of every email IDM sends
-- ----------------------------------------------------------------------------
-- IDM gains the ability to email people (rename notices, alias-expiry reminders,
-- etc.). Every send is logged here — recipients, subject, body, status, and any
-- error — so operators can see what went out, retry failures, and audit
-- notifications. The Mailer writes a 'queued' row, attempts delivery through the
-- configured transport, then updates the row to 'sent' or 'failed'. When the
-- transport is unconfigured the row stays 'queued' (nothing is lost).
-- ============================================================================

CREATE TABLE email_outbox (
  id          BIGINT       NOT NULL AUTO_INCREMENT,
  person_id   BIGINT       NULL,                    -- related person, if any
  to_addr     VARCHAR(500) NOT NULL,               -- comma-separated recipients
  cc_addr     VARCHAR(500) NULL,
  subject     VARCHAR(300) NOT NULL,
  body        MEDIUMTEXT   NOT NULL,
  status      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  error       VARCHAR(1000) NULL,
  context     VARCHAR(60)  NULL,                    -- e.g. 'rename_notice', 'alias_reminder'
  created_by  VARCHAR(120) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at     DATETIME     NULL,

  PRIMARY KEY (id),
  KEY ix_outbox_person (person_id),
  KEY ix_outbox_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
