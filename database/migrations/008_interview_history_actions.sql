-- ============================================================
-- Migration 008: Interview History actions (reschedule support)
-- Adds columns that let a rescheduled interview preserve its
-- previous schedule on the SAME record (no duplicate rows), and
-- exposes the lifecycle statuses used by the centralized
-- Interview History page.
--
-- The "interviews" table already stores one row per interview
-- thread and a "status" enum (scheduled/rescheduled/completed/
-- cancelled/no_show). To represent a reschedule's "Previous ->
-- Current" schedule without creating duplicate interview records,
-- we store the previous date/time/location here.
--
-- Idempotent — safe to re-run.
-- ============================================================

USE hr1_database;

-- Previous schedule date (populated when an interview is rescheduled)
SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='previous_date');
SET @s1 = IF(@c1=0, 'ALTER TABLE interviews ADD COLUMN previous_date DATETIME NULL AFTER interview_time', 'SELECT "interviews.previous_date exists" AS message');
PREPARE p1 FROM @s1; EXECUTE p1; DEALLOCATE PREPARE p1;

-- Previous schedule time
SET @c2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='previous_time');
SET @s2 = IF(@c2=0, 'ALTER TABLE interviews ADD COLUMN previous_time TIME NULL AFTER previous_date', 'SELECT "interviews.previous_time exists" AS message');
PREPARE p2 FROM @s2; EXECUTE p2; DEALLOCATE PREPARE p2;

-- Previous schedule location
SET @c3 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hr1_database' AND TABLE_NAME='interviews' AND COLUMN_NAME='previous_location');
SET @s3 = IF(@c3=0, 'ALTER TABLE interviews ADD COLUMN previous_location VARCHAR(150) NULL AFTER previous_time', 'SELECT "interviews.previous_location exists" AS message');
PREPARE p3 FROM @s3; EXECUTE p3; DEALLOCATE PREPARE p3;
