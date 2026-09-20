-- =====================================================================
-- Migration 030: Audit Log metadata on security_log
--
-- Extends the existing security_log audit table (reused, not duplicated)
-- with the context an Audit Log view needs:
--     username   / role        -> actor snapshot at the time of the event
--     module     / target_type / target_id -> what was acted upon
--     user_agent               -> browser/client string of the actor
--     status                   -> 'success' | 'failure'
--
-- The pre-existing columns (user_id, event_type, ip_address, details,
-- created_at) are untouched so legacy rows and queries keep working.
-- Never stores passwords, hashes, tokens, or session secrets.
--
-- Run: C:\xampp\mysql\bin\mysql.exe -u root hr1_database < database/migrations/030_audit_log_columns.sql
--
-- Rollback:
--   ALTER TABLE security_log
--       DROP COLUMN status, DROP COLUMN user_agent, DROP COLUMN target_id,
--       DROP COLUMN target_type, DROP COLUMN module, DROP COLUMN role,
--       DROP COLUMN username;
-- =====================================================================

USE hr1_database;

ALTER TABLE security_log
    ADD COLUMN IF NOT EXISTS username    VARCHAR(191) NULL DEFAULT NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS role        VARCHAR(32)  NULL DEFAULT NULL AFTER username,
    ADD COLUMN IF NOT EXISTS module      VARCHAR(64)  NULL DEFAULT NULL AFTER role,
    ADD COLUMN IF NOT EXISTS target_type VARCHAR(64)  NULL DEFAULT NULL AFTER module,
    ADD COLUMN IF NOT EXISTS target_id   INT          NULL DEFAULT NULL AFTER target_type,
    ADD COLUMN IF NOT EXISTS user_agent  VARCHAR(512) NULL DEFAULT NULL AFTER ip_address,
    ADD COLUMN IF NOT EXISTS status      VARCHAR(16)  NULL DEFAULT NULL AFTER user_agent;

CREATE INDEX IF NOT EXISTS idx_sl_module ON security_log (module);
CREATE INDEX IF NOT EXISTS idx_sl_target ON security_log (target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_sl_status ON security_log (status);