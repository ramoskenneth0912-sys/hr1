-- ============================================================
-- Migration 020: AI Resume Matching — screening status tracking
-- Adds status + error_message columns to the existing ai_screening
-- table so the automatic AI analysis can represent:
--   pending   = analysis queued / in progress
--   analyzed  = valid server-side result stored
--   failed    = analysis could not be completed (API down, resume
--               unreadable, invalid response) — app submission is
--               NEVER blocked; the applicant record stays intact.
-- Safe to re-run (guards via information_schema).
-- ============================================================

USE hr1_database;

-- 1. status column
SET @has_status = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'ai_screening'
      AND COLUMN_NAME = 'status'
);

SET @status_sql = IF(
    @has_status = 0,
    "ALTER TABLE ai_screening ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'analyzed' AFTER screened_by",
    'SELECT "ai_screening.status already exists — no change needed." AS message'
);

PREPARE stmt FROM @status_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. error_message column (internal diagnostics only — never rendered)
SET @has_err = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'ai_screening'
      AND COLUMN_NAME = 'error_message'
);

SET @err_sql = IF(
    @has_err = 0,
    "ALTER TABLE ai_screening ADD COLUMN error_message VARCHAR(500) NULL AFTER status",
    'SELECT "ai_screening.error_message already exists — no change needed." AS message'
);

PREPARE stmt2 FROM @err_sql;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. Existing rows were produced by completed (manual) runs → mark analyzed.
UPDATE ai_screening
SET status = 'analyzed'
WHERE status IS NULL OR status = '';