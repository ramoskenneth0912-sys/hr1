-- =====================================================================
-- Migration 035: Default employee identifier for the HR1 integration
--
-- The HR1 system/integration standardizes on a default employee number
-- (E001). It is stored as RUNTIME configuration in system_settings — the
-- same key/value store that already holds default_department and
-- default_employment_type — so the default can be changed without code
-- edits, without touching employees.id (existing primary keys unchanged),
-- and without hardcoding the internal database id anywhere.
--
-- No schema change; this is a configuration seed only. Resolvers map the
-- number through employees.employee_no (UNIQUE index) → employee row →
-- internal employees.id.
--
-- Run: C:\xampp\mysql\bin\mysql.exe -u root hr1_database < database/migrations/035_default_employee_no.sql
--
-- Rollback:
--   DELETE FROM system_settings WHERE setting_key = 'default_employee_no';
-- =====================================================================

USE hr1_database;

INSERT INTO system_settings (setting_key, setting_value)
SELECT 'default_employee_no', 'E001'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'default_employee_no');