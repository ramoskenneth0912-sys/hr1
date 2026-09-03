-- ============================================================
-- Migration 015: Applicant Onboarding Workflow
-- Adds stage-tracked onboarding for hired applicants before
-- they become active employees. Idempotent — safe to re-run.
-- ============================================================

USE hr1_database;

-- 1. Add onboarding tracking columns to applicants table
SET @has_stage = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'applicants'
      AND COLUMN_NAME = 'onboarding_stage'
);

SET @alter_stage = IF(
    @has_stage = 0,
    'ALTER TABLE applicants
     ADD COLUMN onboarding_status VARCHAR(40) DEFAULT NULL AFTER acceptance_email_sent_at,
     ADD COLUMN onboarding_stage VARCHAR(40) DEFAULT NULL AFTER onboarding_status,
     ADD COLUMN employee_account_created_at DATETIME NULL AFTER onboarding_stage',
    'SELECT "applicants onboarding columns already exist" AS message'
);

PREPARE stmt FROM @alter_stage;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Onboarding progress table (per-applicant stage tracking)
CREATE TABLE IF NOT EXISTS onboarding_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    current_stage VARCHAR(40) NOT NULL DEFAULT 'orientation_scheduled',
    started_at DATETIME NOT NULL,
    orientation_completed_at DATETIME NULL,
    documents_verified_at DATETIME NULL,
    account_created_at DATETIME NULL,
    onboarding_completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_onboarding_applicant (applicant_id),
    CONSTRAINT fk_onboarding_progress_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Orientation schedules table (per-applicant)
CREATE TABLE IF NOT EXISTS orientation_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    orientation_date DATE NOT NULL,
    orientation_time TIME NOT NULL,
    location VARCHAR(200) NOT NULL,
    venue VARCHAR(200) DEFAULT NULL,
    notes TEXT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orientation_applicant (applicant_id),
    CONSTRAINT fk_orientation_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    CONSTRAINT fk_orientation_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4. Onboarding documents table (per-applicant document tracking)
CREATE TABLE IF NOT EXISTS onboarding_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    document_type VARCHAR(60) NOT NULL,
    document_name VARCHAR(150) NOT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verified_by INT NULL,
    verified_at DATETIME NULL,
    rejection_reason TEXT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_onboarding_doc_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    CONSTRAINT fk_onboarding_doc_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
