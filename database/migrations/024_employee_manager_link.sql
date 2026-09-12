-- =====================================================================
-- Migration 024: Employee manager relationship — "My Performance" (Phase 2)
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Target:  MariaDB 10.4.32
--
-- WHAT THIS CHANGES (and nothing else):
--   Adds ONE nullable column manager_id to the existing employees table.
--   No employee row is modified — the column stays NULL until HR assigns
--   a manager through the application. No manager "structure" table is
--   invented; a self-referencing FK reuses the existing employees table.
--
-- PURPOSE IN THE PERFORMANCE DESIGN:
--   - Managers (users.role = 'manager') can only access reviews of
--     employees where employees.manager_id = <manager's employee id>,
--     PLUS reviews where they are the explicit reviewer_user_id.
--   - reviewer_user_id remains the practical reviewer mechanism until
--     real manager assignments exist.
--
-- Rollback:
--   ALTER TABLE employees DROP FOREIGN KEY fk_employees_manager,
--                        DROP KEY idx_employees_manager,
--                        DROP COLUMN manager_id;
-- =====================================================================

USE hr1_database;

SET @has_mgr = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'employees'
      AND COLUMN_NAME = 'manager_id'
);

SET @mgr_sql = IF(
    @has_mgr = 0,
    "ALTER TABLE employees
        ADD COLUMN manager_id INT NULL AFTER department_id,
        ADD KEY idx_employees_manager (manager_id),
        ADD CONSTRAINT fk_employees_manager
            FOREIGN KEY (manager_id) REFERENCES employees (id) ON DELETE SET NULL",
    'SELECT "employees.manager_id already exists — no change needed." AS message'
);

PREPARE stmt_mgr FROM @mgr_sql;
EXECUTE stmt_mgr;
DEALLOCATE PREPARE stmt_mgr;