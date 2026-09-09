-- ============================================================
-- Migration 022: Hybrid AI Screening — v2 result metadata
-- Adds explainability + version columns to the existing ai_screening
-- table WITHOUT touching existing rows (all new columns are nullable):
--
--   screening_version              = engine tag, e.g. "hybrid-v2" (new rows)
--                                    Existing rows keep NULL so old scores are
--                                    never silently relabeled.
--   confidence                     = High / Medium / Low
--   partial_requirements           = JSON list (JSON-validated, like the
--                                    existing matched/missing columns)
--   evidence                       = JSON list of evidence strings
--   concerns                       = JSON list of caution notes
--
-- Existing screening records, applicant history and the workflow are
-- preserved. Safe to re-run (guards via information_schema).
-- ============================================================

USE hr1_database;

-- 1. screening_version
SET @has_v = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'ai_screening'
      AND COLUMN_NAME = 'screening_version'
);

SET @v_sql = IF(
    @has_v = 0,
    "ALTER TABLE ai_screening ADD COLUMN screening_version VARCHAR(40) NULL AFTER error_message",
    'SELECT "ai_screening.screening_version already exists — no change needed." AS message'
);

PREPARE stmt FROM @v_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. confidence
SET @has_c = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'ai_screening'
      AND COLUMN_NAME = 'confidence'
);

SET @c_sql = IF(
    @has_c = 0,
    "ALTER TABLE ai_screening ADD COLUMN confidence VARCHAR(20) NULL AFTER screening_version",
    'SELECT "ai_screening.confidence already exists — no change needed." AS message'
);

PREPARE stmt2 FROM @c_sql;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. partial_requirements (JSON validated)
SET @has_p = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'ai_screening'
      AND COLUMN_NAME = 'partial_requirements'
);

SET @p_sql = IF(
    @has_p = 0,
    "ALTER TABLE ai_screening ADD COLUMN partial_requirements LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER confidence",
    'SELECT "ai_screening.partial_requirements already exists — no change needed." AS message'
);

PREPARE stmt3 FROM @p_sql;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- 4. evidence (JSON validated)
SET @has_e = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'ai_screening'
      AND COLUMN_NAME = 'evidence'
);

SET @e_sql = IF(
    @has_e = 0,
    "ALTER TABLE ai_screening ADD COLUMN evidence LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER partial_requirements",
    'SELECT "ai_screening.evidence already exists — no change needed." AS message'
);

PREPARE stmt4 FROM @e_sql;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- 5. concerns (JSON validated)
SET @has_conc = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'ai_screening'
      AND COLUMN_NAME = 'concerns'
);

SET @conc_sql = IF(
    @has_conc = 0,
    "ALTER TABLE ai_screening ADD COLUMN concerns LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER evidence",
    'SELECT "ai_screening.concerns already exists — no change needed." AS message'
);

PREPARE stmt5 FROM @conc_sql;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

-- 6. JSON validation constraints (match the existing matched/missing pattern).
SET @has_pchk = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'ai_screening'
      AND CONSTRAINT_TYPE = 'CHECK'
      AND CONSTRAINT_NAME = 'ok_match_chk'
);

SET @pchk_sql = IF(
    @has_pchk = 0,
    "ALTER TABLE ai_screening ADD CONSTRAINT ok_match_chk CHECK (json_valid(partial_requirements))",
    'SELECT "ok_match_chk already exists — no change needed." AS message'
);

PREPARE stmt6 FROM @pchk_sql;
EXECUTE stmt6;
DEALLOCATE PREPARE stmt6;

SET @has_echk = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'ai_screening'
      AND CONSTRAINT_TYPE = 'CHECK'
      AND CONSTRAINT_NAME = 'ok_evid_chk'
);

SET @echk_sql = IF(
    @has_echk = 0,
    "ALTER TABLE ai_screening ADD CONSTRAINT ok_evid_chk CHECK (json_valid(evidence))",
    'SELECT "ok_evid_chk already exists — no change needed." AS message'
);

PREPARE stmt7 FROM @echk_sql;
EXECUTE stmt7;
DEALLOCATE PREPARE stmt7;

SET @has_conchk = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'ai_screening'
      AND CONSTRAINT_TYPE = 'CHECK'
      AND CONSTRAINT_NAME = 'ok_conc_chk'
);

SET @conchk_sql = IF(
    @has_conchk = 0,
    "ALTER TABLE ai_screening ADD CONSTRAINT ok_conc_chk CHECK (json_valid(concerns))",
    'SELECT "ok_conc_chk already exists — no change needed." AS message'
);

PREPARE stmt8 FROM @conchk_sql;
EXECUTE stmt8;
DEALLOCATE PREPARE stmt8;

-- No data backfill is performed: existing rows keep screening_version NULL so
-- old results are never silently relabeled as the new engine.