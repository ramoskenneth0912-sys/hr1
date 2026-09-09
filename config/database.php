<?php
/**
 * Database configuration for Merchandising Management System
 * Subsystem: Recruitment and Onboarding / Core HR
 *
 * Local XAMPP development works with the defaults below (no secrets in
 * code). For staging/production, set environment variables instead of
 * editing this file:
 *   HR1_DB_HOST, HR1_DB_NAME, HR1_DB_USER, HR1_DB_PASS, HR1_DB_PORT,
 *   HR1_BASE_URL
 * These values are used server-side only and are never rendered into HTML.
 */

require_once __DIR__ . '/../includes/environment.php';

define('DB_HOST', getenv('HR1_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('HR1_DB_NAME') ?: 'hr1_database');
define('DB_USER', getenv('HR1_DB_USER') ?: 'root');
define('DB_PASS', getenv('HR1_DB_PASS') !== false ? getenv('HR1_DB_PASS') : '');
define('DB_PORT', (int) (getenv('HR1_DB_PORT') ?: 0));
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Merchandising Management System');
define('APP_SUBSYSTEM', 'Recruitment & Onboarding / Core HR');
// An explicitly-set HR1_BASE_URL (including an intentional empty one for a
// domain root) is honored; only an unset variable falls back to the local
// XAMPP path prefix.
define('BASE_URL', getenv('HR1_BASE_URL') === false ? '/HR1' : rtrim(getenv('HR1_BASE_URL'), '/'));

function getConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . (DB_PORT > 0 ? ';port=' . DB_PORT : '')
            . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}

function db(): PDO
{
    return getConnection();
}

// Fail fast (production only) before any DB connection or request handling if
// the required production configuration is missing or unsafe.
hr1_assert_production_config();
