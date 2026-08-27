-- ============================================================
-- Migration 007: Interview Scheduling fields
-- Reuses the existing "interviews" table and extends it additively
-- with the fields needed for the HR scheduling workflow.
-- Idempotent — safe to re-run.
-- ============================================================

USE hr1_database;

-- Add application_id (mirrors the applicant/application row)
SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='application_id');
SET @s1 = IF(@c1=0, 'ALTER TABLE interviews ADD COLUMN application_id INT NULL AFTER applicant_id', 'SELECT "interviews.application_id exists" AS message');
PREPARE p1 FROM @s1; EXECUTE p1; DEALLOCATE PREPARE p1;

-- Add interview_time
SET @c2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='interview_time');
SET @s2 = IF(@c2=0, 'ALTER TABLE interviews ADD COLUMN interview_time TIME NULL AFTER interview_date', 'SELECT "interviews.interview_time exists" AS message');
PREPARE p2 FROM @s2; EXECUTE p2; DEALLOCATE PREPARE p2;

-- Add company_name
SET @c3 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='company_name');
SET @s3 = IF(@c3=0, 'ALTER TABLE interviews ADD COLUMN company_name VARCHAR(150) NULL AFTER location', 'SELECT "interviews.company_name exists" AS message');
PREPARE p3 FROM @s3; EXECUTE p3; DEALLOCATE PREPARE p3;

-- Add company_address
SET @c4 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='company_address');
SET @s4 = IF(@c4=0, 'ALTER TABLE interviews ADD COLUMN company_address VARCHAR(255) NULL AFTER company_name', 'SELECT "interviews.company_address exists" AS message');
PREPARE p4 FROM @s4; EXECUTE p4; DEALLOCATE PREPARE p4;

-- Add additional_instructions
SET @c5 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='additional_instructions');
SET @s5 = IF(@c5=0, 'ALTER TABLE interviews ADD COLUMN additional_instructions TEXT NULL AFTER interviewer', 'SELECT "interviews.additional_instructions exists" AS message');
PREPARE p5 FROM @s5; EXECUTE p5; DEALLOCATE PREPARE p5;

-- Add internal_notes (private HR info — never shown to applicants)
SET @c6 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='internal_notes');
SET @s6 = IF(@c6=0, 'ALTER TABLE interviews ADD COLUMN internal_notes TEXT NULL AFTER additional_instructions', 'SELECT "interviews.internal_notes exists" AS message');
PREPARE p6 FROM @s6; EXECUTE p6; DEALLOCATE PREPARE p6;

-- Add status (Scheduled / Rescheduled / Completed / Cancelled / No Show)
SET @c7 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='status');
SET @s7 = IF(@c7=0,
    'ALTER TABLE interviews ADD COLUMN status ENUM(''scheduled'',''rescheduled'',''completed'',''cancelled'',''no_show'') NOT NULL DEFAULT ''scheduled'' AFTER internal_notes',
    'SELECT "interviews.status exists" AS message');
PREPARE p7 FROM @s7; EXECUTE p7; DEALLOCATE PREPARE p7;

-- Add updated_at
SET @c8 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='updated_at');
SET @s8 = IF(@c8=0,
    'ALTER TABLE interviews ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    'SELECT "interviews.updated_at exists" AS message');
PREPARE p8 FROM @s8; EXECUTE p8; DEALLOCATE PREPARE p8;

-- Duplicate-active-interview index (supports the application-level guard)
SET @i1 = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND INDEX_NAME='idx_interviews_app_status');
SET @si1 = IF(@i1=0, 'ALTER TABLE interviews ADD INDEX idx_interviews_app_status (application_id, status)', 'SELECT "index exists" AS message');
PREPARE pi1 FROM @si1; EXECUTE pi1; DEALLOCATE PREPARE pi1;
