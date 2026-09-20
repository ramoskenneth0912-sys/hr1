-- =====================================================================
-- Migration 034: User archive workflow
--
-- Adds a soft-archive state to the existing users table. Archiving
-- removes the account from the active Users list (WHERE is_archived = 0)
-- while retaining the row and all historical records; it never deletes.
--
--     is_archived TINYINT(1) NOT NULL DEFAULT 0
--        0 -> active account, 1 -> archived account
--     archived_at DATETIME NULL
--        when the archive happened (NULL until archived)
--     archived_by INT NULL
--        users.id of the HR/Manager who performed the archive
--        (soft reference, matching the project's applicants.user_id
--        pattern; no hard FK so historical rows survive user removal)
--
-- The existing is_active (Deactivate) semantics are untouched:
--   Deactivate -> is_active = 0 (revoke sign-in, keep account visible)
--   Archive    -> is_archived = 1 (remove from active list, keep everything)
--
-- Run: C:\xampp\mysql\bin\mysql.exe -u root hr1_database < database/migrations/034_user_archive.sql
--
-- Rollback:
--   ALTER TABLE users
--       DROP COLUMN archived_by, DROP COLUMN archived_at, DROP COLUMN is_archived;
-- =====================================================================

USE hr1_database;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL AFTER is_archived,
    ADD COLUMN IF NOT EXISTS archived_by INT NULL DEFAULT NULL AFTER archived_at;