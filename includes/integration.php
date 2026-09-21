<?php
/**
 * HR1 integration helpers — default employee identifier + resolution.
 *
 * The HR1 system/integration standardizes on a default employee referenced by
 * its public employee number (e.g. E001) instead of an internal database id.
 * The default number is RUNTIME configuration stored in system_settings
 * (setting_key = 'default_employee_no') — the same key/value store that already
 * holds default_department and default_employment_type — so it can be changed
 * without code edits, never requires the database primary key, and stays
 * compatible with every other employee number already in the system.
 *
 * Resolution always goes through employees.employee_no, which carries a UNIQUE
 * index, so the mapping employee number -> employee row (-> internal id) is 1:1.
 *
 * Loaded by api/v1/bootstrap.php for the API; dependency-free of any framework
 * state so CLI tooling can use it too.
 */

declare(strict_types=1);

if (!function_exists('hr1_default_employee_no')) {
    /**
     * The configured default employee number (e.g. 'E001').
     * Falls back to '' when system_settings is missing the key.
     */
    function hr1_default_employee_no(): string
    {
        $stmt = db()->prepare(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'default_employee_no' LIMIT 1"
        );
        $stmt->execute();
        return strtoupper(trim((string) ($stmt->fetchColumn() ?: '')));
    }

    /**
     * Resolve an employee number to its employee row, or null when unknown.
     * Normalises to upper-case to match the E-series convention
     * (employee_no carries a UNIQUE index → 1:1 lookup).
     */
    function hr1_resolve_employee_no(string $employeeNo): ?array
    {
        $employeeNo = strtoupper(trim($employeeNo));
        if ($employeeNo === '') {
            return null;
        }
        $stmt = db()->prepare('SELECT * FROM employees WHERE employee_no = :no LIMIT 1');
        $stmt->execute([':no' => $employeeNo]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}