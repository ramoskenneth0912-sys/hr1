-- =====================================================================
-- Migration 003: Local environment restoration + settings storage
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Created: 2026-08-22
--
-- ADDITIVE ONLY — creates missing tables/columns, never drops or alters
-- existing data. Idempotent (safe to re-run on MariaDB 10.4+).
--
-- Closes the gap found when this project was copied to a new machine:
--   * system_settings / notification_preferences — required by the
--     Settings module but defined in no earlier migration file
--   * notifications / api_tokens — defined by migrations 001/002,
--     not yet applied on this machine's database copy
--   * users.phone — written by Settings > Account, column was missing
--   * ess_profiles extras — migration 002 columns, not yet applied
--   * applicants status enum gains 'shortlisted' + screening columns
--     (migration 001 additions)
--
-- Rollback:
--   DROP TABLE IF EXISTS notification_preferences;
--   DROP TABLE IF EXISTS system_settings;
--   DROP TABLE IF EXISTS notifications;
--   DROP TABLE IF EXISTS api_tokens;
--   ALTER TABLE users DROP COLUMN phone;
--   ALTER TABLE ess_profiles
--       DROP COLUMN photo_path, DROP COLUMN work_experience,
--       DROP COLUMN skills, DROP COLUMN education;
-- =====================================================================

USE hr1_database;

-- ---- Settings module key/value store --------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Per-account notification toggles (Settings > Notifications) ----------
CREATE TABLE IF NOT EXISTS notification_preferences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    pref_key VARCHAR(100) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_notification_prefs (user_id, pref_key),
    CONSTRAINT fk_notif_prefs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Per-user notifications for the header bell (migration 002 item) ------
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, is_read, created_at);

-- ---- API bearer-token store (migration 001 item) ---------------------------
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    name VARCHAR(80) NULL DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user (user_id),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- users.phone — used by Settings > Account ------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL DEFAULT NULL AFTER email;

-- ---- ess_profiles extras — migration 002 items ------------------------------
ALTER TABLE ess_profiles
    ADD COLUMN IF NOT EXISTS education VARCHAR(150) NULL AFTER address,
    ADD COLUMN IF NOT EXISTS skills TEXT NULL AFTER education,
    ADD COLUMN IF NOT EXISTS work_experience TEXT NULL AFTER skills,
    ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL AFTER work_experience;

-- ---- applicants: migration 001 items ----------------------------------------
ALTER TABLE applicants
    MODIFY COLUMN status ENUM('new','screening','shortlisted','interview','offered','hired','rejected') NOT NULL DEFAULT 'new';

ALTER TABLE applicants
    ADD COLUMN IF NOT EXISTS education VARCHAR(150) NULL AFTER address,
    ADD COLUMN IF NOT EXISTS skills TEXT NULL AFTER education,
    ADD COLUMN IF NOT EXISTS work_experience TEXT NULL AFTER skills;
