-- =====================================================================
-- Migration 028: Employee development planning — ESS "My Development"
-- Adds employee-owned development plans and roadmap activities.
-- Goals, performance reviews, competencies, training, and learning remain
-- owned by their existing modules/sources and are not duplicated here.
--
-- Rollback:
--   DROP TABLE IF EXISTS development_activities, development_plans;
-- =====================================================================

USE hr1_database;

CREATE TABLE IF NOT EXISTS development_plans (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT NOT NULL COMMENT 'FK → employees(id); owner of the plan',
    title        VARCHAR(160) NOT NULL COMMENT 'Development objective or plan title',
    focus_area   VARCHAR(80) NULL COMMENT 'Development focus area',
    description  TEXT NULL COMMENT 'Development objective detail',
    target_date  DATE NULL,
    status       ENUM('draft','in_progress','completed','cancelled')
                 NOT NULL DEFAULT 'draft',
    progress     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    notes        TEXT NULL COMMENT 'Employee-owned plan notes',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_development_plans_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
    CONSTRAINT chk_development_plans_progress CHECK (progress BETWEEN 0 AND 100),
    KEY idx_development_plans_employee (employee_id),
    KEY idx_development_plans_status (status),
    KEY idx_development_plans_target_date (target_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS development_activities (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id      INT UNSIGNED NOT NULL,
    title        VARCHAR(160) NOT NULL,
    description  TEXT NULL,
    target_date  DATE NULL,
    status       ENUM('not_started','in_progress','completed','cancelled')
                 NOT NULL DEFAULT 'not_started',
    progress     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    notes        TEXT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_development_activities_plan
        FOREIGN KEY (plan_id) REFERENCES development_plans (id) ON DELETE CASCADE,
    CONSTRAINT chk_development_activities_progress CHECK (progress BETWEEN 0 AND 100),
    KEY idx_development_activities_plan (plan_id),
    KEY idx_development_activities_status (status),
    KEY idx_development_activities_target_date (target_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
