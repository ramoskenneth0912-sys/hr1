-- =====================================================================
-- Migration 023: Employee Goals — ESS "My Goals" backend (Phase 1)
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Target:  MariaDB 10.4.32
--
-- WHAT THIS CHANGES (and nothing else):
--   NEW TABLE employee_goals — the employee self-service goal registry.
--   No existing table, employee record, department, or auth data is touched.
--
-- HOW IT CONNECTS TO EXISTING HR1 TABLES:
--   employee_goals.employee_id  → employees(id)   (FK, ON DELETE CASCADE)
--   employee_goals.assigned_by  → users(id)       (FK, ON DELETE SET NULL)
--
--   The authenticated user (users.employee_id) determines ownership at
--   request time inside the API, so employees' own goals are derived from
--   the existing session/identity chain, never from client-supplied IDs.
--   A "manager/supervisor" reporting structure does not exist in HR1, so
--   none is invented here.
--
-- STATUS MODEL:
--   Stored statuses (final, decided by HR/process): not_started, in_progress,
--   completed, cancelled. 'overdue' is never stored — it is derived at read
--   time by the API when due_date < CURDATE() and the goal is not finalized.
--
-- Rollback:
--   DROP TABLE IF EXISTS employee_goals;
-- =====================================================================

USE hr1_database;

CREATE TABLE IF NOT EXISTS employee_goals (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT 'FK → employees(id); owner of the goal',
    title       VARCHAR(160) NOT NULL COMMENT 'Goal title',
    description TEXT NULL COMMENT 'Goal description (optional detail)',
    category    VARCHAR(60) NULL COMMENT 'Category/type, e.g. Performance, Skill, Project',
    target      VARCHAR(255) NULL COMMENT 'Target/deliverable',
    start_date  DATE NULL COMMENT 'Start date',
    due_date    DATE NULL COMMENT 'Target/end date',
    progress    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100, validated server-side',
    status      ENUM('not_started','in_progress','completed','cancelled')
                NOT NULL DEFAULT 'not_started'
                COMMENT "'overdue' is derived at read time, never stored",
    priority    ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    notes       TEXT NULL COMMENT 'Employee progress notes (employee-editable)',
    assigned_by INT NULL COMMENT 'FK → users(id); who assigned the goal (HR side)',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_employee_goals_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_goals_assigner
        FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_employee_goals_progress CHECK (progress BETWEEN 0 AND 100),
    KEY idx_employee_goals_employee (employee_id),
    KEY idx_employee_goals_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;