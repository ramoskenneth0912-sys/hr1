-- =====================================================================
-- Migration 031: Salary / Compensation on job_postings
--
-- Adds an optional free-text Salary / Compensation field to job postings.
-- HR enters the actual compensation wording for the specific position
-- (e.g. "₱20,000 – ₱25,000 per month", "Negotiable"). It is:
--     - manually entered by HR (never derived from department/title/type),
--     - blank by default (NULL),
--     - only shown on the public job posting when provided.
-- Free text keeps a single VARCHAR column (no salary_min/salary_max pair);
-- the table previously had NO salary field, so nothing is duplicated.
--
-- Run: C:\xampp\mysql\bin\mysql.exe -u root hr1_database < database/migrations/031_job_salary_compensation.sql
--
-- Rollback:
--   ALTER TABLE job_postings DROP COLUMN salary_compensation;
-- =====================================================================

USE hr1_database;

ALTER TABLE job_postings
    ADD COLUMN IF NOT EXISTS salary_compensation VARCHAR(200) NULL DEFAULT NULL AFTER job_employment_type;