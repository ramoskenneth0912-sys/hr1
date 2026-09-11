-- =====================================================================
-- Migration 029: HR-owned employee recognition records
-- Recognition is authored by HR or an authorized manager and is read-only
-- for employees through the employee-scoped API.
--
-- Rollback:
--   DROP TABLE IF EXISTS employee_recognitions;
-- =====================================================================

USE hr1_database;

CREATE TABLE IF NOT EXISTS employee_recognitions (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_employee_id INT NOT NULL,
    issuer_user_id        INT NOT NULL,
    category              VARCHAR(80) NOT NULL,
    title                 VARCHAR(160) NOT NULL,
    message               TEXT NOT NULL,
    recognition_date      DATE NOT NULL,
    status                ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_employee_recognitions_recipient
        FOREIGN KEY (recipient_employee_id) REFERENCES employees (id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_recognitions_issuer
        FOREIGN KEY (issuer_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    KEY idx_employee_recognitions_recipient (recipient_employee_id, status, recognition_date),
    KEY idx_employee_recognitions_issuer (issuer_user_id),
    KEY idx_employee_recognitions_date (recognition_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
