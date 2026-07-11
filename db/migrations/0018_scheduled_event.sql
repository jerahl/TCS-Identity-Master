-- ============================================================================
-- 0018 — scheduled_event: a durable delayed-events queue
-- ----------------------------------------------------------------------------
-- Actions that must happen at a FUTURE time rather than now: the username/email
-- cutover 7 days after a rename is approved, removal of the old email alias 90
-- days later, and the reminder emails in between. A row becomes due when run_at
-- passes; bin/run_scheduled_events.php (a cron/timer) claims due rows, dispatches
-- them by event_type, and marks the outcome. Idempotent: a handler that fails is
-- left 'pending' (with attempts/last_error) to retry on the next run.
--
-- dedupe_key (optional) makes scheduling idempotent — re-running the code that
-- schedules a cutover for the same person/rename won't create a duplicate.
-- ============================================================================

CREATE TABLE scheduled_event (
  id          BIGINT       NOT NULL AUTO_INCREMENT,
  person_id   BIGINT       NULL,                    -- subject (null for non-person events)
  event_type  VARCHAR(60)  NOT NULL,                -- username_cutover / alias_remove / alias_reminder / …
  run_at      DATETIME     NOT NULL,                -- becomes due at/after this time
  payload     TEXT         NULL,                    -- JSON, event-specific
  status      ENUM('pending','done','failed','canceled') NOT NULL DEFAULT 'pending',
  attempts    INT          NOT NULL DEFAULT 0,
  last_error  VARCHAR(1000) NULL,
  dedupe_key  VARCHAR(190) NULL,                    -- optional; unique when set
  created_by  VARCHAR(120) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_sched_due (status, run_at),
  KEY ix_sched_person (person_id),
  UNIQUE KEY uq_sched_dedupe (dedupe_key),
  CONSTRAINT fk_sched_person FOREIGN KEY (person_id) REFERENCES person(person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
