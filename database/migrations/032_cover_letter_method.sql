-- =====================================================================
-- Migration 032: Cover letter method + uploaded cover letter path
--
-- The applicant cover letter was previously stored ONLY as plain text
-- in applicants.notes (the same field HR1 has always used). This adds
-- the smallest possible schema for the JobStreet-style "Upload OR Write"
-- flow without touching the existing notes storage:
--
--   cover_letter_method ENUM('write','upload')
--       'write'  -> the cover letter text lives in the existing notes column
--       'upload' -> the cover letter is a stored file (cover_letter_path)
--       NULL     -> legacy rows / HR-created applicants; treated as 'write'
--   cover_letter_path VARCHAR(255)
--       relative path under /uploads for uploaded cover letters, else NULL
--
-- Existing applications (text in notes) keep working with no data change.
--
-- Run: C:\xampp\mysql\bin\mysql.exe -u root hr1_database < database/migrations/032_cover_letter_method.sql
--
-- Rollback:
--   ALTER TABLE applicants DROP COLUMN cover_letter_method;
--   ALTER TABLE applicants DROP COLUMN cover_letter_path;
-- =====================================================================

USE hr1_database;

ALTER TABLE applicants
    ADD COLUMN IF NOT EXISTS cover_letter_method ENUM('write','upload') NULL DEFAULT NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS cover_letter_path VARCHAR(255) NULL DEFAULT NULL AFTER cover_letter_method;