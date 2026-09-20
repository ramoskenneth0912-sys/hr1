-- =====================================================================
-- Migration 033: About the Role on job_postings
--
-- Adds an HR-editable concise role overview that is displayed to
-- applicants under the "About the Role" heading on the public job
-- posting page. It is intentionally separate from the detailed
-- job description (description column):
--
--   about_role    -> concise overview (why the role exists, main
--                    purpose, general responsibilities) shown to
--                    applicants under ABOUT THE ROLE.
--   description   -> the detailed/duty-level job description already
--                    managed by HR1.
--
-- Optional (blank allowed) so existing job creation is unaffected;
-- existing postings get populated afterwards so applicants always
-- have useful role-overview information.
--
-- Run: C:\xampp\mysql\bin\mysql.exe -u root hr1_database < database/migrations/033_about_role.sql
--
-- Rollback:
--   ALTER TABLE job_postings DROP COLUMN about_role;
-- =====================================================================

USE hr1_database;

ALTER TABLE job_postings
    ADD COLUMN IF NOT EXISTS about_role TEXT NULL DEFAULT NULL AFTER description;