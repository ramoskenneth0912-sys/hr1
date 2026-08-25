-- Run this ONLY if you already imported the old schema and got the duplicate FK error.
-- This adds the missing foreign key safely (skips if it already exists).

USE hr1_database;

SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'employee_onboarding'
      AND CONSTRAINT_NAME = 'fk_onboarding_employee'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE employee_onboarding ADD CONSTRAINT fk_onboarding_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE',
    'SELECT "Foreign key fk_onboarding_employee already exists — no action needed." AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
