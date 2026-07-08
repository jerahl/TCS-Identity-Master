-- ============================================================================
-- 0014 — Editable orientation-checklist templates + a "notify" audit trail
--
--   * notify_template holds the editable content for each checklist variant
--     (new_teacher / non_instructional): a heading, an intro paragraph, and a
--     body in a small, safe line-based markup (## section / - item, with
--     [label](url) links). No row yet = the app falls back to built-in defaults
--     (App\Service\NotifyTemplateService::defaults), so this table is optional to
--     populate — editors override defaults by saving, reset by clearing.
--   * The audit_log.action and lifecycle_event.event_type enums gain a `notify`
--     value so generating a checklist (single or bulk) is recorded like any other
--     action, with a per-person timeline entry.
-- ============================================================================

CREATE TABLE IF NOT EXISTS notify_template (
  doc         VARCHAR(40)  NOT NULL,                 -- 'new_teacher' | 'non_instructional'
  heading     VARCHAR(160) NOT NULL,
  intro       TEXT         NULL,
  body        MEDIUMTEXT   NULL,                     -- safe mini-markup (## / - / [label](url))
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by  VARCHAR(60)  NULL,
  PRIMARY KEY (doc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Preserve the existing values (login/logout came from 0002) and add 'notify'.
ALTER TABLE audit_log
  MODIFY COLUMN action ENUM('insert','update','delete','merge','login','logout','notify') NOT NULL;

ALTER TABLE lifecycle_event
  MODIFY COLUMN event_type
    ENUM('create','update','disable','enable','terminate','convert','merge','username_assigned','notify') NOT NULL;
