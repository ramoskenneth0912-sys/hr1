-- ============================================================
-- Migration 019: Employee Salary Information
-- Adds salary/pay structure columns to the HR1 employees table
-- to prepare for the future HR1 <-> HR4 Payroll integration.
--
-- HR4 DOES NOT EXIST YET and is NOT created here. These columns
-- only extend the existing HR1 employee/HCM record so HR1 can
-- display salary information once available. Idempotent.
-- ============================================================

USE hr1_database;

-- salary_type: how the base salary is denominated (monthly/hourly/etc.)
SET @has_salary_type = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'employees'
      AND COLUMN_NAME = 'salary_type'
);
SET @alter_salary_type = IF(
    @has_salary_type = 0,
    'ALTER TABLE employees
     ADD COLUMN salary_type VARCHAR(40) DEFAULT NULL AFTER salary',
    'SELECT "employees.salary_type already exists" AS message'
);
PREPARE stmt_salary_type FROM @alter_salary_type;
EXECUTE stmt_salary_type;
DEALLOCATE PREPARE stmt_salary_type;

-- pay_frequency: payroll cadence (monthly, semi_monthly, weekly, etc.)
SET @has_pay_frequency = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'employees'
      AND COLUMN_NAME = 'pay_frequency'
);
SET @alter_pay_frequency = IF(
    @has_pay_frequency = 0,
    'ALTER TABLE employees
     ADD COLUMN pay_frequency VARCHAR(40) DEFAULT NULL AFTER salary_type',
    'SELECT "employees.pay_frequency already exists" AS message'
);
PREPARE stmt_pay_frequency FROM @alter_pay_frequency;
EXECUTE stmt_pay_frequency;
DEALLOCATE PREPARE stmt_pay_frequency;

-- currency: ISO code for the salary denomination (e.g. PHP)
SET @has_currency = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'hr1_database'
      AND TABLE_NAME = 'employees'
      AND COLUMN_NAME = 'currency'
);
SET @alter_currency = IF(
    @has_currency = 0,
    'ALTER TABLE employees
     ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT ''PHP'' AFTER pay_frequency',
    'SELECT "employees.currency already exists" AS message'
);
PREPARE stmt_currency FROM @alter_currency;
EXECUTE stmt_currency;
DEALLOCATE PREPARE stmt_currency;
