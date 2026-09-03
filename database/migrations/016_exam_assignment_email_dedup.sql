-- ============================================================
-- Migration 016: Exam Assignment Email Dedup + HR3 Provisioning
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Created: 2026-09-03
--
-- PURPOSE
--   1. Add exam_email_sent_at to exam_assignments to prevent
--      duplicate exam-assignment emails to applicants.
--   2. Add unique constraint on exams.external_ref to prevent
--      duplicate exam imports from HR3.
--   3. Add exams.provided_by / exams.provisioned_at tracking.
--
-- Idempotent — safe to re-run.
-- ============================================================

USE hr1_database;

-- 1. exam_assignments.exam_email_sent_at (dedup guard for the
--    "Your examination is now available" email)
SET @col1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_assignments'
                AND COLUMN_NAME = 'exam_email_sent_at');
SET @ddl1 := IF(@col1 = 0,
    'ALTER TABLE exam_assignments ADD COLUMN exam_email_sent_at DATETIME NULL DEFAULT NULL AFTER invited_at',
    'SELECT "exam_assignments.exam_email_sent_at already exists" AS msg');
PREPARE stmt1 FROM @ddl1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- 2. exams.external_ref UNIQUE KEY (prevent duplicate HR3 exam imports)
SET @uniq2 := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams'
                 AND INDEX_NAME = 'uq_exams_external_ref');
SET @ddl2 := IF(@uniq2 = 0,
    'ALTER TABLE exams ADD UNIQUE KEY uq_exams_external_ref (external_ref)',
    'SELECT "exams.uq_exams_external_ref already exists" AS msg');
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. exams.provisioned_at (when HR3 provided the exam)
SET @col3 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams'
                AND COLUMN_NAME = 'provisioned_at');
SET @ddl3 := IF(@col3 = 0,
    'ALTER TABLE exams ADD COLUMN provisioned_at DATETIME NULL DEFAULT NULL AFTER external_ref',
    'SELECT "exams.provisioned_at already exists" AS msg');
PREPARE stmt3 FROM @ddl3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;
