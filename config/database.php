<?php
/**
 * Database configuration for Merchandising Management System
 * Subsystem: Recruitment and Onboarding / Core HR
 *
 * Local XAMPP development works with the defaults below (no secrets in
 * code). For staging/production, set environment variables instead of
 * editing this file:
 *   HR1_DB_HOST, HR1_DB_NAME, HR1_DB_USER, HR1_DB_PASS, HR1_BASE_URL
 * These values are used server-side only and are never rendered into HTML.
 */

define('DB_HOST', getenv('HR1_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('HR1_DB_NAME') ?: 'hr1_database');
define('DB_USER', getenv('HR1_DB_USER') ?: 'root');
define('DB_PASS', getenv('HR1_DB_PASS') !== false ? getenv('HR1_DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Merchandising Management System');
define('APP_SUBSYSTEM', 'Recruitment & Onboarding / Core HR');
define('BASE_URL', getenv('HR1_BASE_URL') ?: '/HR1');

function getConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
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
