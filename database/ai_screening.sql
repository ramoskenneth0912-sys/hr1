-- ============================================================
-- Migration: AI Applicant Resume Screening
-- Adds ai_screening table and job_posting_id to applicants
-- Safe to re-run (uses IF NOT EXISTS)
-- ============================================================

USE hr1_database;

-- 1. Add job_posting_id to applicants table
SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'applicants'
      AND COLUMN_NAME = 'job_posting_id'
);

SET @alter_sql = IF(
    @has_col = 0,
    'ALTER TABLE applicants ADD COLUMN job_posting_id INT NULL AFTER department_id',
    'SELECT "applicants.job_posting_id already exists — no change needed." AS message'
);

PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add index on job_posting_id
SET @has_idx = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'applicants'
      AND INDEX_NAME = 'idx_job_posting_id'
);

SET @idx_sql = IF(
    @has_idx = 0,
    'ALTER TABLE applicants ADD INDEX idx_job_posting_id (job_posting_id)',
    'SELECT "Index idx_job_posting_id already exists — no change needed." AS message'
);

PREPARE stmt2 FROM @idx_sql;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. Create ai_screening table
CREATE TABLE IF NOT EXISTS ai_screening (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    job_posting_id INT NOT NULL,
    overall_score INT DEFAULT 0,
    skills_score INT DEFAULT 0,
    experience_score INT DEFAULT 0,
    education_score INT DEFAULT 0,
    qualifications_score INT DEFAULT 0,
    recommendation VARCHAR(50) DEFAULT 'Unscreened',
    matched_requirements JSON,
    missing_requirements JSON,
    ai_analysis TEXT,
    screened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    screened_by VARCHAR(120) DEFAULT NULL,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    FOREIGN KEY (job_posting_id) REFERENCES job_postings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Auto-link applicants to job_postings via position_applied match (best effort)
UPDATE applicants a
INNER JOIN job_postings j ON a.position_applied = j.title AND a.job_posting_id IS NULL
SET a.job_posting_id = j.id
WHERE a.job_posting_id IS NULL;
