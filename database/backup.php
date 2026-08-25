<?php
/**
 * Database backup script for HR1.
 *
 * Uses mysqldump to create timestamped SQL backups of hr1_database
 * with automatic 30-backup rotation.
 *
 * Security: Only HR role users can execute this script.
 * Storage: Backups are saved to storage/backups/ (web-access blocked by .htaccess).
 *
 * Usage (CLI):
 *   php database/backup.php
 *
 * Usage (Web):
 *   Only accessible by logged-in HR users via the admin panel.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireHRor();

$backupDir = __DIR__ . '/../storage/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true);
}

$timestamp = date('Y-m-d_His');
$filename  = "hr1_backup_{$timestamp}.sql";
$filepath  = $backupDir . '/' . $filename;

$mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';

if (!file_exists($mysqldump)) {
    echo "Error: mysqldump not found at {$mysqldump}\n";
    exit(1);
}

$cmd = sprintf(
    '"%s" --host=%s --user=%s --password=%s --single-transaction --routines --triggers "%s" > "%s" 2>&1',
    $mysqldump,
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    escapeshellarg(DB_PASS),
    DB_NAME,
    $filepath
);

$output = [];
$returnCode = 0;
exec($cmd, $output, $returnCode);

if ($returnCode !== 0) {
    echo "Error: mysqldump failed (exit code {$returnCode})\n";
    echo implode("\n", $output) . "\n";
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    exit(1);
}

if (!file_exists($filepath) || filesize($filepath) === 0) {
    echo "Error: Backup file is empty or was not created\n";
    exit(1);
}

$size = filesize($filepath);
echo "Backup created: {$filename} ({$size} bytes)\n";

$maxBackups = 30;
$backups = glob($backupDir . '/hr1_backup_*.sql');
usort($backups, function ($a, $b) {
    return filemtime($b) - filemtime($a);
});

if (count($backups) > $maxBackups) {
    $toDelete = array_slice($backups, $maxBackups);
    foreach ($toDelete as $old) {
        unlink($old);
        echo "Deleted old backup: " . basename($old) . "\n";
    }
}

echo "Done. " . count(glob($backupDir . '/hr1_backup_*.sql')) . " backup(s) retained.\n";
exit(0);
