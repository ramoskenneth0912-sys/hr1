-- =====================================================================
-- Migration 010: API Key authentication for system-to-system communication
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Created: 2026-08-31
--
-- WHAT THIS DOES:
--   1. NEW TABLE api_keys — stores hashed API keys for trusted
--      system-to-system integrations. The plaintext key is shown once
--      at creation time and never stored.
--   2. NEW TABLE api_key_audit — records every authentication attempt
--      (success/failure) with endpoint, timestamp, and IP metadata.
--      Never stores the actual API key value.
--
-- KEY FORMAT:
--   hr1_ + 48 random bytes base64url-encoded = 64 characters total.
--   Only SHA-256 hashes are stored in api_keys.key_hash.
--
-- SCOPES (JSON array stored in api_keys.scopes):
--   "applicants:read"    — read applicant data
--   "applicants:write"   — create/update/delete applicants
--   "jobs:read"          — read job postings
--   "jobs:write"         — create/update/delete job postings
--   "employees:read"     — read employee data
--   "employees:write"    — create/update/delete employee data
--   "users:read"         — list/view user accounts
--   "users:write"        — create/update/delete users
--   "departments:read"   — list departments
--   "admin:read"         — view dashboard stats
--   "admin:write"        — change application statuses
--
-- Rollback:
--   DROP TABLE IF EXISTS api_key_audit;
--   DROP TABLE IF EXISTS api_keys;
-- =====================================================================

USE hr1_database;

-- ============================================================
-- 1. API KEYS — trusted system-to-system credentials
-- ============================================================
CREATE TABLE IF NOT EXISTS api_keys (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    key_prefix VARCHAR(12) NOT NULL,
    scopes JSON NOT NULL,
    created_by INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    expires_at DATETIME NULL DEFAULT NULL,
    last_used_at DATETIME NULL DEFAULT NULL,
    revoked_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_api_keys_hash (key_hash),
    KEY idx_api_keys_active (is_active, expires_at, revoked_at),
    KEY idx_api_keys_created_by (created_by),
    CONSTRAINT fk_api_keys_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. API KEY AUDIT LOG — authentication attempt records
-- ============================================================
CREATE TABLE IF NOT EXISTS api_key_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT UNSIGNED NULL DEFAULT NULL,
    key_prefix VARCHAR(12) NULL DEFAULT NULL,
    event_type VARCHAR(32) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL DEFAULT NULL,
    status ENUM('success','failure') NOT NULL,
    failure_reason VARCHAR(100) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aka_key_id (api_key_id),
    KEY idx_aka_event (event_type, created_at),
    KEY idx_aka_created (created_at),
    CONSTRAINT fk_aka_key FOREIGN KEY (api_key_id) REFERENCES api_keys (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
