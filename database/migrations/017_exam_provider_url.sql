-- ============================================================
-- Migration 017: exam provider/examination URL field
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Created: 2026-09-03
--
-- PURPOSE
--   Add exams.exam_url — the HR3-provided examination-taking URL that
--   the HR1 tokenized access page ("Start Examination" button) launches.
--
--   HR3 owns exam content/UI and returns the result to HR1 via the
--   existing integration boundary. HR1 stores ONLY the exam reference +
--   this provider URL. No question/answer/score duplication in HR1.
--
--   Also add exam_assignments.begun_at to record when the applicant
--   first launched the examination (defensive audit + status transition).
--
-- Rollback:
--   ALTER TABLE exams DROP COLUMN exam_url;
--   ALTER TABLE exam_assignments DROP COLUMN begun_at;
-- ============================================================

USE hr1_database;

-- 1. exams.exam_url (nullable HTTPS provider/exam-taking URL)
SET @col1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams'
                AND COLUMN_NAME = 'exam_url');
SET @ddl1 := IF(@col1 = 0,
    'ALTER TABLE exams ADD COLUMN exam_url VARCHAR(500) NULL DEFAULT NULL AFTER instructions',
    'SELECT "exams.exam_url already exists" AS msg');
PREPARE stmt1 FROM @ddl1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- 2. exam_assignments.begun_at (when applicant first launched the exam)
SET @col2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_assignments'
                AND COLUMN_NAME = 'begun_at');
SET @ddl2 := IF(@col2 = 0,
    'ALTER TABLE exam_assignments ADD COLUMN begun_at DATETIME NULL DEFAULT NULL AFTER invited_at',
    'SELECT "exam_assignments.begun_at already exists" AS msg');
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
