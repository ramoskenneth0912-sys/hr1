-- ============================================================
-- Migration: Add users table, extend job_postings, seed data
-- Safe to re-run (uses IF NOT EXISTS / stored procedures)
-- ============================================================

USE hr1_database;

-- ============================================================
-- 1. USERS TABLE (authentication)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('hr', 'manager', 'employee') NOT NULL DEFAULT 'employee',
    employee_id INT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
);

-- ============================================================
-- 2. EXTENDED COLUMNS FOR job_postings
--    MySQL does not support ADD COLUMN IF NOT EXISTS,
--    so we use a stored procedure that checks information_schema.
-- ============================================================
DELIMITER $$

DROP PROCEDURE IF EXISTS sp_add_column_if_missing$$

CREATE PROCEDURE sp_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- Add extended columns to job_postings
CALL sp_add_column_if_missing('job_postings', 'qualifications',         'TEXT AFTER requirements');
CALL sp_add_column_if_missing('job_postings', 'required_skills',        'TEXT AFTER qualifications');
CALL sp_add_column_if_missing('job_postings', 'education_requirement',  'VARCHAR(150) AFTER required_skills');
CALL sp_add_column_if_missing('job_postings', 'experience_requirement', 'VARCHAR(150) AFTER education_requirement');
CALL sp_add_column_if_missing('job_postings', 'work_location',         'VARCHAR(150) AFTER experience_requirement');
CALL sp_add_column_if_missing('job_postings', 'job_employment_type',   "ENUM('regular','contractual','probationary','part_time','internship') DEFAULT 'regular' AFTER work_location");

-- Clean up the helper procedure
DROP PROCEDURE IF EXISTS sp_add_column_if_missing;

-- ============================================================
-- 3. DEFAULT ADMIN USER
--    Password: admin123  (bcrypt via PHP password_hash)
-- ============================================================
INSERT INTO users (username, email, password_hash, role)
SELECT 'admin', 'admin@company.com', '$2y$10$WtZRPj1oZVqOXGQLBS6YfusG1L7IfXQM/In9H7619uyMRxRvrbQR2', 'hr'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin' OR email = 'admin@company.com');

-- ============================================================
-- 4. SEED SAMPLE JOB POSTINGS (idempotent)
-- ============================================================
-- Only insert if no open postings exist yet
INSERT INTO job_postings (
    job_code, title, department_id, description, requirements, vacancies,
    status, posted_date, closing_date,
    qualifications, required_skills, education_requirement,
    experience_requirement, work_location, job_employment_type
)
SELECT * FROM (
    SELECT
        'JOB00001', 'Marketing Specialist', 1,
        'We are looking for a creative Marketing Specialist to develop and implement marketing strategies that drive brand awareness and customer engagement.',
        'Proven experience in marketing', 2, 'open', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        'Bachelor degree in Marketing or related field',
        'SEO, Social Media Marketing, Content Creation, Google Analytics',
        'Bachelor''s Degree in Marketing, Communications, or related field',
        '2+ years in marketing role',
        'Makati City, Metro Manila', 'regular'
    UNION ALL SELECT
        'JOB00002', 'Operations Coordinator', 2,
        'Seeking an organized Operations Coordinator to streamline daily business operations and improve workflow efficiency.',
        'Strong organizational skills', 1, 'open', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        'Experience in operations or logistics management',
        'Supply Chain Management, Inventory Control, MS Office, Problem Solving',
        'Bachelor''s Degree in Business Administration or related field',
        '1-3 years in operations',
        'BGC, Taguig City', 'regular'
    UNION ALL SELECT
        'JOB00003', 'IT Support Specialist', 5,
        'Join our IT team to provide technical support and maintain our systems and infrastructure.',
        'Knowledge of IT systems and troubleshooting', 3, 'open', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        'IT certifications preferred (CompTIA, CCNA)',
        'Hardware Troubleshooting, Networking, Windows/Linux OS, Help Desk Support',
        'Bachelor''s Degree in Information Technology or related field',
        '1+ years IT support experience',
        'Ortigas, Pasig City', 'regular'
    UNION ALL SELECT
        'JOB00004', 'Finance Analyst', 3,
        'Looking for a detail-oriented Finance Analyst to support financial planning and analysis activities.',
        'Strong analytical skills', 1, 'open', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        'CPA certification is a plus',
        'Financial Analysis, Excel Advanced, SAP, Budgeting, Reporting',
        'Bachelor''s Degree in Finance, Accounting, or related field',
        '2+ years in financial analysis',
        'Makati City, Metro Manila', 'regular'
    UNION ALL SELECT
        'JOB00005', 'HR Assistant', 4,
        'We need a people-oriented HR Assistant to support our Human Resources department with day-to-day operations.',
        'Excellent communication skills', 1, 'open', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        'Experience in HR administration',
        'Recruitment, Employee Relations, MS Office, Payroll Processing, HRIS',
        'Bachelor''s Degree in HR Management or related field',
        '1+ years HR experience',
        'Makati City, Metro Manila', 'regular'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM job_postings WHERE status = 'open' LIMIT 1);

-- ============================================================
-- 5. ADD APPLICANT ROLE TO users ENUM
-- ============================================================
SET @col_type = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
);

SET @alter_sql = IF(
    @col_type NOT LIKE '%applicant%',
    'ALTER TABLE users MODIFY COLUMN role ENUM(''hr'', ''manager'', ''employee'', ''applicant'') NOT NULL DEFAULT ''employee''',
    'SELECT "Role column already contains applicant — no change needed." AS message'
);

PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 6. SEED TEST APPLICANT USER (password: applicant123)
-- ============================================================
INSERT IGNORE INTO users (username, email, password_hash, role, is_active) VALUES
    ('applicant', 'applicant@test.com', '$2y$10$rnGHlE.hFwfdq4qWtgw8Ru.ZvKBlvjPipOApc9/pDHdCKSD7g.cCy', 'applicant', 1);

-- ============================================================
-- 7. ADD user_id TO applicants TABLE (link applications to accounts)
-- ============================================================
SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'applicants'
      AND COLUMN_NAME = 'user_id'
);

SET @alter_sql2 = IF(
    @has_col = 0,
    'ALTER TABLE applicants ADD COLUMN user_id INT(11) UNSIGNED NULL AFTER id',
    'SELECT "applicants.user_id already exists — no change needed." AS message'
);

PREPARE stmt2 FROM @alter_sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
