-- ============================================================================
-- 0003 — first-class import sources for interns, long-term subs, contractors
-- Extend the system/source enums so these feeds keep distinct provenance instead
-- of collapsing into 'manual'. Additive only.
-- ============================================================================

ALTER TABLE import_batch
  MODIFY system ENUM('nextgen','powerschool','manual','intern','sub','contractor') NOT NULL;

ALTER TABLE staging_record
  MODIFY system ENUM('nextgen','powerschool','manual','intern','sub','contractor') NOT NULL;

ALTER TABLE assignment
  MODIFY source ENUM('nextgen','powerschool','manual','intern','sub','contractor') NOT NULL DEFAULT 'nextgen';

ALTER TABLE person
  MODIFY source_of_record ENUM('nextgen','manual','powerschool','intern','sub','contractor') NOT NULL DEFAULT 'nextgen';

-- Crosswalk: interns already use 'intern_csv'; add 'sub' and 'contractor'.
ALTER TABLE person_source_id
  MODIFY system ENUM('nextgen','powerschool','ad','google','intern_csv','alsde','onesync','manual','sub','contractor') NOT NULL;
