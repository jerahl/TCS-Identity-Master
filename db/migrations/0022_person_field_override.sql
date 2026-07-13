-- Per-field "manually overridden" flags. When an operator hand-edits a
-- golden-record field (the person Edit form or the source-reconciliation panel),
-- the field is pinned here so subsequent feed imports leave it alone instead of
-- reverting it to the source value. One row per pinned (person, field); `field`
-- is the golden-record column name, or 'title' for the primary assignment title.
CREATE TABLE IF NOT EXISTS person_field_override (
  person_id  INT          NOT NULL,
  field      VARCHAR(64)  NOT NULL,            -- golden column name, or 'title'
  actor      VARCHAR(60)  NULL,                -- who pinned it
  note       VARCHAR(255) NULL,                -- optional reason / context
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (person_id, field),
  CONSTRAINT fk_pfo_person FOREIGN KEY (person_id)
    REFERENCES person(person_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
