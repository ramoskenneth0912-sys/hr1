-- =====================================================================
-- Migration 004: Password reset tokens
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Created: 2026-08-25
--
-- ADDITIVE ONLY — creates the password_resets table for the
-- forgot-password / reset-password flow. Idempotent.
--
-- Rollback:
--   DROP TABLE IF EXISTS password_resets;
-- =====================================================================

USE hr1_database;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(120) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_password_resets_email (email),
    KEY idx_password_resets_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
