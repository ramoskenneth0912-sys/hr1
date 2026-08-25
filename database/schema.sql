-- Merchandising Management System
-- Subsystem 1: Recruitment and Onboarding / Core HR

CREATE DATABASE IF NOT EXISTS hr1_database
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hr1_database;

-- Departments (shared reference)
CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- MODULE 1: APPLICANT MANAGEMENT
-- ============================================================
CREATE TABLE IF NOT EXISTS applicants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    applicant_no VARCHAR(20) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(120) NOT NULL,
    phone VARCHAR(30),
    address TEXT,
    position_applied VARCHAR(120) NOT NULL,
    department_id INT,
    resume_path VARCHAR(255),
    status ENUM('new', 'screening', 'interview', 'offered', 'hired', 'rejected') DEFAULT 'new',
    applied_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- ============================================================
-- MODULE 2: RECRUITMENT MANAGEMENT
-- ============================================================
CREATE TABLE IF NOT EXISTS job_postings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_code VARCHAR(20) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    department_id INT,
    description TEXT,
    requirements TEXT,
    qualifications TEXT,
    required_skills TEXT,
    education_requirement VARCHAR(150),
    experience_requirement VARCHAR(150),
    work_location VARCHAR(150),
    job_employment_type ENUM('regular', 'contractual', 'probationary', 'part_time', 'internship') DEFAULT 'regular',
    vacancies INT DEFAULT 1,
    status ENUM('draft', 'open', 'closed', 'filled') DEFAULT 'draft',
    posted_date DATE,
    closing_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS interviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    job_posting_id INT,
    interview_date DATETIME NOT NULL,
    interviewer VARCHAR(120),
    location VARCHAR(150),
    result ENUM('pending', 'passed', 'failed', 'rescheduled') DEFAULT 'pending',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
    FOREIGN KEY (job_posting_id) REFERENCES job_postings(id) ON DELETE SET NULL
);

-- ============================================================
-- MODULE 4: CORE HUMAN CAPITAL MANAGEMENT (HCM)
-- (Created before onboarding — employee_onboarding references employees)
-- ============================================================
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_no VARCHAR(20) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    phone VARCHAR(30),
    department_id INT,
    job_title VARCHAR(120) NOT NULL,
    employment_type ENUM('regular', 'contractual', 'probationary', 'part_time') DEFAULT 'regular',
    hire_date DATE NOT NULL,
    status ENUM('active', 'on_leave', 'terminated', 'resigned') DEFAULT 'active',
    salary DECIMAL(12, 2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- ============================================================
-- MODULE 3: NEW HIRE ONBOARDING
-- ============================================================
CREATE TABLE IF NOT EXISTS onboarding_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_name VARCHAR(150) NOT NULL,
    description TEXT,
    category ENUM('documentation', 'orientation', 'training', 'equipment', 'compliance') DEFAULT 'documentation',
    is_required TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0
);

CREATE TABLE IF NOT EXISTS employee_onboarding (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    task_id INT NOT NULL,
    status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
    completed_date DATE,
    completed_by VARCHAR(120),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES onboarding_tasks(id) ON DELETE CASCADE
);

-- ============================================================
-- MODULE 5: EMPLOYEE SELF SERVICE (ESS)
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type ENUM('vacation', 'sick', 'emergency', 'maternity', 'paternity', 'unpaid') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
    approved_by VARCHAR(120),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ess_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL UNIQUE,
    emergency_contact_name VARCHAR(120),
    emergency_contact_phone VARCHAR(30),
    address TEXT,
    education VARCHAR(150),
    skills TEXT,
    work_experience TEXT,
    photo_path VARCHAR(255),
    birth_date DATE,
    marital_status ENUM('single', 'married', 'widowed', 'separated') DEFAULT 'single',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- ============================================================
-- EMPLOYEE NOTIFICATIONS (header bell)
-- ============================================================
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

CREATE INDEX idx_notifications_user ON notifications (user_id, is_read, created_at);

-- ============================================================
-- MODULE 6: EMPLOYEE RECORDS MANAGEMENT
-- ============================================================
CREATE TABLE IF NOT EXISTS employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    document_type ENUM('contract', 'id', 'certificate', 'evaluation', 'disciplinary', 'other') NOT NULL,
    document_name VARCHAR(150) NOT NULL,
    file_path VARCHAR(255),
    issue_date DATE,
    expiry_date DATE,
    notes TEXT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS employment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    event_type ENUM('hire', 'promotion', 'transfer', 'salary_change', 'disciplinary', 'termination', 'resignation') NOT NULL,
    event_date DATE NOT NULL,
    description TEXT NOT NULL,
    recorded_by VARCHAR(120),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- ============================================================
-- AUTHENTICATION & USER MANAGEMENT
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('hr', 'manager', 'employee', 'applicant') NOT NULL DEFAULT 'employee',
    employee_id INT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
);

-- Seed data (safe to re-run)
INSERT IGNORE INTO departments (code, name) VALUES
    ('MKT', 'Marketing'),
    ('OPS', 'Operations'),
    ('FIN', 'Finance'),
    ('HR', 'Human Resources'),
    ('IT', 'Information Technology');

INSERT IGNORE INTO onboarding_tasks (task_name, description, category, sort_order) VALUES
    ('Submit Government IDs', 'Provide SSS, PhilHealth, Pag-IBIG, and TIN documents', 'documentation', 1),
    ('Sign Employment Contract', 'Review and sign the official employment contract', 'documentation', 2),
    ('Company Orientation', 'Attend new hire orientation session', 'orientation', 3),
    ('Department Introduction', 'Meet team members and department head', 'orientation', 4),
    ('POS System Training', 'Complete point-of-sale system training', 'training', 5),
    ('Issue Uniform & ID', 'Receive company uniform and employee ID', 'equipment', 6),
    ('Safety & Compliance Briefing', 'Complete workplace safety and compliance training', 'compliance', 7);

-- No default user accounts are seeded with known passwords.
-- Create the first HR account with the CLI tool (never via web):
--   php database/create_admin.php
-- It generates a strong random password, stores only its bcrypt hash,
-- and prints the one-time password to the local console.
