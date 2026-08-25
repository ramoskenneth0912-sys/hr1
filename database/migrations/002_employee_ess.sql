-- Migration 002 — Employee Self-Service Portal
-- 1) Self-maintained profile fields on the existing ESS profile table
-- 2) Per-user notifications for the header bell
-- Safe to re-run.

USE hr1_database;

-- ---- ess_profiles: employee-editable extras -------------------------------
ALTER TABLE ess_profiles
    ADD COLUMN IF NOT EXISTS education VARCHAR(150) NULL AFTER address,
    ADD COLUMN IF NOT EXISTS skills TEXT NULL AFTER education,
    ADD COLUMN IF NOT EXISTS work_experience TEXT NULL AFTER skills,
    ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL AFTER work_experience;

-- ---- notifications: one row per event, scoped to a user account -----------
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, is_read, created_at);
