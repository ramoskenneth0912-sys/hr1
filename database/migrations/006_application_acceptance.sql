-- ============================================================
-- Migration 006: Application Acceptance
-- Adds "accepted" and "passed_screening" status values to the
-- applicants.status ENUM, and an acceptance_email_sent_at column
-- used to prevent duplicate acceptance emails.
-- Idempotent — safe to re-run.
-- ============================================================

USE hr1_database;

-- 1. Extend applicants.status ENUM with accepted / passed_screening
SET @has_status = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'applicants'
      AND COLUMN_NAME = 'status'
      AND COLUMN_TYPE LIKE '%accepted%'
);

SET @alter_status = IF(
    @has_status = 0,
    'ALTER TABLE applicants
     MODIFY COLUMN status ENUM(''new'',''screening'',''shortlisted'',''interview'',''offered'',''hired'',''rejected'',''accepted'',''passed_screening'') NOT NULL DEFAULT ''new''',
    'SELECT "applicants.status already includes acceptance values — no change needed." AS message'
);

PREPARE stmt FROM @alter_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add acceptance_email_sent_at column (dedup guard)
SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'applicants'
      AND COLUMN_NAME = 'acceptance_email_sent_at'
);

SET @alter_col = IF(
    @has_col = 0,
    'ALTER TABLE applicants ADD COLUMN acceptance_email_sent_at DATETIME NULL AFTER status',
    'SELECT "applicants.acceptance_email_sent_at already exists — no change needed." AS message'
);

PREPARE stmt2 FROM @alter_col;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
