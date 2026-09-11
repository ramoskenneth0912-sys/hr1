-- =====================================================================
-- Migration 027: Employee competency assignments — ESS "My Competencies" (Phase 3A)
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Target:  MariaDB 10.4.32
--
-- WHAT THIS CHANGES (and nothing else):
--   NEW TABLE employee_competencies — links an employee to a catalog
--   competency with a required (target) level and an assessed current level.
--   No existing table, employee record, department, or auth data is touched.
--
-- HOW IT CONNECTS TO EXISTING HR1 TABLES:
--   employee_competencies.employee_id  → employees(id)  (FK, ON DELETE CASCADE)
--   employee_competencies.competency_id → competencies(id) (FK, ON DELETE CASCADE)
--   employee_competencies.evaluated_by → users(id)      (FK, ON DELETE SET NULL)
--   employee_competencies.created_by   → users(id)      (FK, ON DELETE SET NULL)
--
-- OWNERSHIP (enforced server-side):
--   For employee endpoints, the authenticated user's identity chain
--   (users.employee_id) determines ownership at request time — never from
--   client-supplied employee IDs. Manager scope reuses HR1's existing
--   manager/reviewer authorization pattern (employees.manager_id OR the
--   user being the practical evaluator).
--
-- LEVEL MODEL (depends on migration 026 + rating_options from migration 025):
--   required_level  1-5   target level from rating_options (scale_code='competency')
--   current_level   1-5   assessed level; NULL until an evaluator assesses it
--   status          active / inactive (assignment level, not catalog level)
--
-- FUTURE INTEGRATION:
--   A later phase will reference this table from review_competency_results
--   (point-in-time snapshot, same pattern as review_goal_results). Nothing
--   is created for that now; this migration only adds the employee-side
--   competency record store.
--
-- DUPLICATES:
--   UNIQUE(employee_id, competency_id) prevents assigning the same catalog
--   competency to the same employee twice.
--
-- Rollback:
--   DROP TABLE IF EXISTS employee_competencies;
-- =====================================================================

USE hr1_database;

CREATE TABLE IF NOT EXISTS employee_competencies (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT NOT NULL COMMENT 'FK → employees(id); the employee',
    competency_id  INT UNSIGNED NOT NULL COMMENT 'FK → competencies(id)',
    required_level TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Target level 1-5 (rating_options scale competency)',
    current_level  TINYINT UNSIGNED NULL COMMENT 'Assessed level 1-5; NULL = not yet evaluated',
    evaluated_by   INT NULL COMMENT 'FK → users(id); who assessed (manager or HR)',
    evaluated_at   DATETIME NULL COMMENT 'When the assessment was made',
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes          TEXT NULL COMMENT 'Evaluator notes or employee self-reflection',
    created_by     INT NULL COMMENT 'FK → users(id); who created this assignment',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_comp_employee_competency (employee_id, competency_id),
    KEY idx_emp_comp_employee (employee_id),
    KEY idx_emp_comp_competency (competency_id),
    KEY idx_emp_comp_status (status),
    CONSTRAINT fk_emp_comp_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_comp_competency
        FOREIGN KEY (competency_id) REFERENCES competencies (id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_comp_evaluator
        FOREIGN KEY (evaluated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_emp_comp_creator
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_emp_comp_required_level CHECK (required_level BETWEEN 1 AND 5),
    CONSTRAINT chk_emp_comp_current_level CHECK (current_level IS NULL OR current_level BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;