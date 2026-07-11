-- ============================================================================
-- 0020 — email_template: admin-editable subject/body for IDM's emails
-- ----------------------------------------------------------------------------
-- The rename workflow sends several emails (the upcoming-change notice, the
-- cutover confirmation, the alias-expiry reminder, and the alias-removed notice).
-- This table lets an admin edit the subject + body of each from the web console
-- instead of touching code. When a row is absent the built-in default text
-- applies (EmailTemplateService::defaults), so the feature works before anything
-- is saved. Bodies use {placeholder} tokens (e.g. {name}, {new_email},
-- {cutover_date}) substituted at send time.
-- ============================================================================

CREATE TABLE email_template (
  template_key VARCHAR(60)  NOT NULL,               -- rename_notice / rename_done / alias_reminder / alias_removed
  subject      VARCHAR(300) NOT NULL,
  body         MEDIUMTEXT   NOT NULL,
  updated_by   VARCHAR(120) NULL,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
