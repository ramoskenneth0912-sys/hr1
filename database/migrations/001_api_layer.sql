-- =====================================================================
-- Migration 001: REST API layer support
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Created: 2026-08-22
--
-- WHAT THIS CHANGES (and nothing else):
--   1. NEW TABLE api_tokens
--      Stores hashed Bearer tokens issued by POST /api/v1/auth/login.
--      This is new API infrastructure only; no existing table is replaced.
--   2. TABLE applicants (ADDITIVE ONLY):
--      - status enum gains 'shortlisted' (existing values stay valid;
--        all current website code keeps working unchanged)
--      - new nullable columns: education, skills, work_experience
--        (exposed by the applications API; existing pages unaffected)
-- Rollback:
--   DROP TABLE IF EXISTS api_tokens;
--   ALTER TABLE applicants
--       MODIFY COLUMN status ENUM('new','screening','interview','offered','hired','rejected') NOT NULL DEFAULT 'new';
--   ALTER TABLE applicants DROP COLUMN work_experience, DROP COLUMN skills, DROP COLUMN education;
-- =====================================================================

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

ALTER TABLE applicants
    MODIFY COLUMN status ENUM('new','screening','shortlisted','interview','offered','hired','rejected') NOT NULL DEFAULT 'new',
    ADD COLUMN education VARCHAR(150) NULL DEFAULT NULL AFTER address,
    ADD COLUMN skills TEXT NULL DEFAULT NULL AFTER education,
    ADD COLUMN work_experience TEXT NULL DEFAULT NULL AFTER skills;
