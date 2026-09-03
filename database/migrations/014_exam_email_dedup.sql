-- ============================================================
-- Migration 014: Exam-Passed Email Dedup Guard
-- Adds exam_email_sent_at column to the applicants table,
-- used to prevent duplicate exam-passed emails.
-- Idempotent — safe to re-run.
-- ============================================================

USE hr1_database;

SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'applicants'
      AND COLUMN_NAME = 'exam_email_sent_at'
);

SET @alter_col = IF(
    @has_col = 0,
    'ALTER TABLE applicants ADD COLUMN exam_email_sent_at DATETIME NULL AFTER acceptance_email_sent_at',
    'SELECT "applicants.exam_email_sent_at already exists — no change needed." AS message'
);

PREPARE stmt FROM @alter_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
