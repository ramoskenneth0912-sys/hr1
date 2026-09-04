-- ============================================================
-- Migration 018: Recruitment Requests
-- Adds a table for incoming recruitment requests from succession
-- planning (future HR3 integration). Idempotent — safe to re-run.
-- ============================================================

USE hr1_database;

CREATE TABLE IF NOT EXISTS recruitment_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(20) NOT NULL UNIQUE,
    position_title VARCHAR(150) NOT NULL,
    department_id INT,
    number_of_positions INT NOT NULL DEFAULT 1,
    reason TEXT,
    required_competencies TEXT,
    required_qualifications TEXT,
    source VARCHAR(100) DEFAULT NULL,
    request_date DATE NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'filled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;
