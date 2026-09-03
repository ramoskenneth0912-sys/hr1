-- =====================================================================
-- Migration 011: HR1 examination side (HR1 <-> HR3 integration boundary)
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Created: 2026-09-03
--
-- PURPOSE
--   Codify the HR1-owned examination schema for the future externa HR3
--   examination integration. HR1 owns the exam REFERENCE, the ASSIGNMENT
--   of an exam to an eligible applicant, and the RESULT LEDGER returned by
--   the external provider. HR1 does NOT store HR3 exam content, questions,
--   correct answers, or scoring logic.
--
--   These tables already exist in the live database as orphaned schema from
--   an earlier (reverted) session. This migration makes the schema
--   reproducible and additive-only: every CREATE IF NOT EXISTS is a no-op
--   when the table already exists, and new columns are added only when
--   missing (via information_schema guards).
--
-- HR1 OWNS (reference + assignment + result ledger):
--   exams             - an HR1-side exam reference bound to a Job/Position
--   exam_assignments  - an exam assigned to one eligible applicant
--   exam_attempts     - an attempt record for an assignment
--   exam_results      - the outcome (score/percentage/pass) returned to HR1
--
-- HR3 OWNS (NOT stored here / not exposed):
--   exam content, questions, correct answers, candidate answers, scoring.
--
-- Rollback:
--   DROP TABLE IF EXISTS exam_results;
--   DROP TABLE IF EXISTS exam_attempts;
--   DROP TABLE IF EXISTS exam_assignments;
--   DROP TABLE IF EXISTS exams;
-- =====================================================================

USE hr1_database;

-- ============================================================
-- 1. EXAMS — HR1-side examination reference (no content/answers)
-- ============================================================
CREATE TABLE IF NOT EXISTS exams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    job_posting_id INT UNSIGNED NULL DEFAULT NULL,
    description TEXT NULL DEFAULT NULL,
    instructions TEXT NULL DEFAULT NULL,
    passing_score DECIMAL(5,2) NOT NULL DEFAULT 60.00,
    time_limit_minutes INT NOT NULL DEFAULT 30,
    max_attempts INT NOT NULL DEFAULT 1,
    source_provider VARCHAR(80) NOT NULL DEFAULT 'HR3',
    external_ref VARCHAR(120) NULL DEFAULT NULL,
    status ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_exams_job (job_posting_id),
    KEY idx_exams_status (status),
    CONSTRAINT fk_exams_job FOREIGN KEY (job_posting_id) REFERENCES job_postings (id) ON DELETE SET NULL,
    CONSTRAINT fk_exams_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. EXAM_ASSIGNMENTS — exam placed against one eligible applicant
--    access_token is the HR1-issued opaque assignment reference
--    (Part 2 contract: assignment_ref), shown to the external provider
--    and the applicant. It must never be a raw DB id.
-- ============================================================
CREATE TABLE IF NOT EXISTS exam_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id INT UNSIGNED NOT NULL,
    applicant_id INT UNSIGNED NOT NULL,
    job_posting_id INT UNSIGNED NULL DEFAULT NULL,
    access_token VARCHAR(128) NOT NULL,
    status ENUM('assigned','in_progress','completed','expired','voided') NOT NULL DEFAULT 'assigned',
    invited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL DEFAULT NULL,
    assigned_by INT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exam_assignment (exam_id, applicant_id),
    UNIQUE KEY uq_exam_access_token (access_token),
    KEY idx_ea_applicant (applicant_id),
    KEY idx_ea_job (job_posting_id),
    KEY idx_ea_status (status),
    CONSTRAINT fk_ea_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE,
    CONSTRAINT fk_ea_applicant FOREIGN KEY (applicant_id) REFERENCES applicants (id) ON DELETE CASCADE,
    CONSTRAINT fk_ea_job FOREIGN KEY (job_posting_id) REFERENCES job_postings (id) ON DELETE SET NULL,
    CONSTRAINT fk_ea_assigned_by FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. EXAM_ATTEMPTS — one sitting of an assignment
-- ============================================================
CREATE TABLE IF NOT EXISTS exam_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    attempt_number INT NOT NULL,
    started_at DATETIME NULL DEFAULT NULL,
    submitted_at DATETIME NULL DEFAULT NULL,
    expiration DATETIME NULL DEFAULT NULL,
    status ENUM('pending','in_progress','submitted','expired') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exam_attempt (assignment_id, attempt_number),
    CONSTRAINT fk_ea_attempt FOREIGN KEY (assignment_id) REFERENCES exam_assignments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. EXAM_RESULTS — outcome ledger returned from the provider.
--    Stores ONLY the outcome (Part 2 HR3->HR1 data): score,
--    percentage, passed, result_status, completion timestamp.
--    Never stores HR3's correct answers or per-question answers.
-- ============================================================
CREATE TABLE IF NOT EXISTS exam_results (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT UNSIGNED NOT NULL,
    earned_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    passed TINYINT(1) NOT NULL DEFAULT 0,
    result_status VARCHAR(20) NULL DEFAULT NULL,
    scored_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exam_result_attempt (attempt_id),
    CONSTRAINT fk_er_attempt FOREIGN KEY (attempt_id) REFERENCES exam_attempts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Additive guards (idempotent) — only add missing columns so this
-- migration is safe against the pre-existing orphaned schema.
-- ============================================================
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_results'
      AND COLUMN_NAME = 'result_status'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE exam_results ADD COLUMN result_status VARCHAR(20) NULL DEFAULT NULL AFTER passed',
    'SELECT "exam_results.result_status already exists" AS msg');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
