-- HR-Verified Password Reset Workflow
-- Creates tables for HR approval workflow and security audit trail
-- Run: C:\xampp\mysql\bin\mysql.exe -u root hr1_database < database/migrations/005_password_reset_requests.sql

-- ============================================================
-- 1. password_reset_requests — approval workflow table
-- ============================================================
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    masked_email VARCHAR(255) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    public_token CHAR(32) NOT NULL UNIQUE,
    requested_by_ip VARCHAR(45) DEFAULT NULL,
    action_by INT DEFAULT NULL,
    action_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (action_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Indexes for common queries
CREATE INDEX idx_prr_status ON password_reset_requests(status);
CREATE INDEX idx_prr_user_id ON password_reset_requests(user_id);
CREATE INDEX idx_prr_expires ON password_reset_requests(expires_at);

-- ============================================================
-- 2. security_log — audit trail for security-relevant events
-- ============================================================
CREATE TABLE IF NOT EXISTS security_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    event_type VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_sl_event ON security_log(event_type);
CREATE INDEX idx_sl_created ON security_log(created_at);
CREATE INDEX idx_sl_user ON security_log(user_id);
