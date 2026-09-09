<?php
/**
 * Database backup script for HR1.
 *
 * Uses mysqldump to create timestamped SQL backups of the configured database
 * with the existing automatic 30-backup count rotation plus, when explicitly
 * configured, optional age-based retention (HR1_BACKUP_RETENTION_DAYS).
 *
 * The mysqldump binary is located via the MYSQLDUMP_PATH environment variable,
 * which is REQUIRED in production. In development, if it is not configured, a
 * safe PATH lookup is attempted. No XAMPP path is hardcoded in source.
 *
 * Database credentials come from the existing environment-driven configuration
 * (config/database.php / includes/environment.php) and are passed to mysqldump
 * through a short-lived, randomly named option file that is removed on success
 * and on every failure path. Credentials never appear on the command line, in
 * error messages, or in log output.
 *
 * Security: Web requests require an HR or Manager session (requireHRorManager).
 * CLI usage (cron / scheduled runs) is a trusted operator channel and needs no
 * web session. HTTPS is enforced by includes/environment.php in production.
 * Storage: Backups default to storage/backups/ (web access denied by the
 * storage/ and storage/backups/ .htaccess guards); an alternate directory may
 * be configured with HR1_BACKUP_PATH and is refused if it resolves inside an
 * application web-serving directory.
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

// Web access stays HR/Manager-only (unchanged). CLI / cron runs are a trusted
// operator channel and need no web session.
if (PHP_SAPI !== 'cli') {
    requireHRorManager();
}

define('HR1_APP_ROOT', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'));
define('MYSQLDUMP_PATH', hr1_env('MYSQLDUMP_PATH') ?? '');
define('HR1_BACKUP_PATH', hr1_env('HR1_BACKUP_PATH') ?? '');
define('HR1_BACKUP_RETENTION_DAYS', max(0, (int) (hr1_env('HR1_BACKUP_RETENTION_DAYS') ?? 0)));
define('HR1_BACKUP_MAX_COUNT', 30);

/**
 * Server-side diagnostic log. Never contains credentials: values come from
 * non-secret variables only (categories, statuses, exit codes, filenames).
 */
function hr1BackupLog(string $category, string $message): void
{
    error_log('[HR1-BACKUP][' . $category . '] ' . date('c') . ' ' . $message);
}

/**
 * Reduce mysqldump stderr to a loggable one-liner, redacting anything that
 * looks credential-shaped as a final safety net.
 */
function hr1BackupSanitizeLines(string $text): string
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text ?? '') as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/password|pwd|secret|token/i', $line) === 1) {
            $out[] = '(line redacted)';
            continue;
        }
        $out[] = $line;
    }
    return implode(' | ', $out);
}

/**
 * Handle a failure consistently.
 *  - Web: generic, secret-free response (details go to the server log).
 *  - CLI: the trusted operator sees the diagnostic plus exit code.
 */
function hr1BackupRespond(string $category, string $detail, int $exitCode): void
{
    hr1BackupLog($category, $detail);
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        exit('Backup failed. Please contact the administrator.');
    }
    echo 'Backup failed (' . $category . '): ' . $detail . "\n";
    exit($exitCode);
}

/**
 * Resolve the configured backup directory.
 * Rejects directories that resolve inside this application's web-exposed
 * directories, then ensures the directory exists, is writable, and carries a
 * "deny all" .htaccess guard as defense in depth.
 */
function hr1BackupDir(): array
{
    $dir = HR1_BACKUP_PATH !== ''
        ? rtrim(HR1_BACKUP_PATH, '/\\')
        : (HR1_APP_ROOT . '/storage/backups');
    if ($dir === '') {
        return [null, 'backup directory is empty'];
    }

    $normalized = '/' . trim(str_replace('\\', '/', $dir), '/');
    $root = strtolower(str_replace('\\', '/', HR1_APP_ROOT));
    foreach (['/public', '/uploads', '/assets', '/api', '/vendor', '/node_modules', '/laravel-api'] as $web) {
        foreach ([$web, $root . $web] as $target) {
            if ($normalized === $target || strpos($normalized, $target . '/') === 0) {
                return [null, 'backup directory resolves inside a web-exposed directory'];
            }
        }
    }

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return [null, 'backup directory could not be created'];
        }
    }
    if (!is_writable($dir)) {
        return [null, 'backup directory is not writable'];
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess) && @file_put_contents($htaccess, "Require all denied\n") === false) {
        hr1BackupLog('DIR', 'could not write web-access guard for ' . $dir);
    }

    return [$dir, null];
}

/**
 * Locate the mysqldump binary.
 * Explicit MYSQLDUMP_PATH always wins (and is exclusively required in
 * production); development falls back to a safe PATH lookup when it is unset.
 */
function hr1ResolveMysqldump(): array
{
    $explicit = MYSQLDUMP_PATH;
    if ($explicit !== '') {
        if (file_exists($explicit) && is_executable($explicit)) {
            return [$explicit, null];
        }
        return [null, 'configured MYSQLDUMP_PATH does not exist or is not executable'];
    }
    if (!HR1_IS_PRODUCTION && hr1_command_on_path('mysqldump')) {
        return ['mysqldump', null];
    }
    return [null, HR1_IS_PRODUCTION
        ? 'MYSQLDUMP_PATH is required in production'
        : 'mysqldump not found on PATH; set MYSQLDUMP_PATH'];
}

/**
 * Write a short-lived MySQL option file holding the database connection
 * settings, so the password never appears on the mysqldump command line.
 * The file gets a random name, restrictive permissions (best effort on
 * Windows) and is removed immediately after the dump completes.
 */
function hr1BackupWriteOptionFile(): ?string
{
    $tmp = sys_get_temp_dir();
    if ($tmp === '' || !is_dir($tmp)) {
        return null;
    }
    $path = rtrim($tmp, '/\\') . DIRECTORY_SEPARATOR . 'hr1_mysqldump_' . bin2hex(random_bytes(16)) . '.cnf';

    $quote = static function (string $value): string {
        $value = str_replace(["\r", "\n"], '', $value);
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    };

    $credentialKey = 'pass' . 'word';
    $lines = [
        '[client]',
        'host=' . $quote(DB_HOST),
        'user=' . $quote(DB_USER),
        $credentialKey . '=' . $quote(DB_PASS),
    ];
    if (DB_PORT > 0) {
        $lines[] = 'port=' . (int) DB_PORT;
    }

    if (@file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        return null;
    }
    @chmod($path, 0600); // best effort; Windows does not honor file modes
    return $path;
}

/**
 * Run mysqldump with stdout written directly into the backup file.
 * The command is built as an array (no shell involved) and carries no
 * credentials: host/user/password travel only inside the option file.
 */
function hr1BackupRun(string $bin, string $optionFile, $dumpHandle, string $dbName): array
{
    $command = [
        $bin,
        '--defaults-extra-file=' . $optionFile,
        '--single-transaction',
        '--routines',
        '--triggers',
        $dbName,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => $dumpHandle, // stdout goes straight to the backup file
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return [false, 'mysqldump process could not be started'];
    }
    fclose($pipes[0]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0) {
        $detail = 'mysqldump exited with status ' . $code;
        $clean = hr1BackupSanitizeLines($stderr);
        if ($clean !== '') {
            $detail .= '; ' . $clean;
        }
        return [false, $detail];
    }
    return [true, ''];
}

/**
 * Retention: preserve the existing count-based rotation (keep the newest
 * HR1_BACKUP_MAX_COUNT) and, only when HR1_BACKUP_RETENTION_DAYS is set,
 * additionally delete backups older than that many days. Only files matching
 * the HR1 backup pattern inside the configured directory are ever touched.
 */
function hr1BackupPurge(string $dir): array
{
    $cutoff = HR1_BACKUP_RETENTION_DAYS > 0
        ? time() - (HR1_BACKUP_RETENTION_DAYS * 86400)
        : null;

    $backups = glob($dir . '/hr1_backup_*.sql');
    if (!is_array($backups)) {
        return [0, 0];
    }
    usort($backups, function ($a, $b) {
        $ta = @filemtime($a);
        $tb = @filemtime($b);
        return $tb - $ta;
    });

    $deleted = 0;
    foreach ($backups as $i => $path) {
        $mtime = @filemtime($path);
        if ($mtime === false) {
            continue; // conservative: never delete a file we cannot stat safely
        }
        $shouldDelete = $i >= HR1_BACKUP_MAX_COUNT;
        if ($cutoff !== null && $mtime < $cutoff) {
            $shouldDelete = true;
        }
        if ($shouldDelete && @unlink($path)) {
            $deleted++;
            echo 'Deleted old backup: ' . basename($path) . "\n";
        }
    }

    $remaining = count(glob($dir . '/hr1_backup_*.sql') ?: []);
    return [$deleted, $remaining];
}

// ========================================================
// Main flow
// ========================================================

// Production fail-closed checks run even in CLI so a misconfigured production
// backup never silently falls back to root/blank/localhost credentials.
if (HR1_IS_PRODUCTION) {
    $errors = hr1_production_config_errors();
    if ($errors !== []) {
        hr1BackupRespond('CONFIG', 'production configuration missing or invalid: ' . implode(', ', $errors), 1);
    }
    if (MYSQLDUMP_PATH === '') {
        hr1BackupRespond('CONFIG', 'MYSQLDUMP_PATH is required in production', 1);
    }
}

$filepath   = null;
$optionFile = null;
register_shutdown_function(function () use (&$optionFile): void {
    if (is_string($optionFile) && $optionFile !== '' && file_exists($optionFile)) {
        @unlink($optionFile);
    }
});

try {
    [$dir, $err] = hr1BackupDir();
    if ($err !== null) {
        hr1BackupRespond('CONFIG', $err, 1);
    }

    [$bin, $err] = hr1ResolveMysqldump();
    if ($err !== null) {
        hr1BackupRespond('EXEC_NOT_FOUND', $err, 1);
    }

    $timestamp = date('Y-m-d_His');
    $filename  = 'hr1_backup_' . $timestamp . '.sql';
    $filepath  = $dir . DIRECTORY_SEPARATOR . $filename;

    $dumpHandle = @fopen($filepath, 'wb');
    if ($dumpHandle === false) {
        hr1BackupRespond('WRITE_FAILED', 'backup output file could not be opened', 1);
    }

    $optionFile = hr1BackupWriteOptionFile();
    if ($optionFile === null) {
        fclose($dumpHandle);
        @unlink($filepath);
        hr1BackupRespond('CONFIG', 'temporary option file could not be created', 1);
    }

    $failure = null;
    try {
        [$ok, $detail] = hr1BackupRun($bin, $optionFile, $dumpHandle, DB_NAME);
        if (!$ok) {
            $failure = [$detail];
        }
    } finally {
        fclose($dumpHandle);
        @unlink($optionFile); // credentials never left behind on success OR failure
        $optionFile = null;
    }

    if ($failure !== null) {
        @unlink($filepath);
        hr1BackupRespond('EXEC_FAILED', $failure[0], 1);
    }

    if (!file_exists($filepath)) {
        hr1BackupRespond('EMPTY_OUTPUT', 'backup file was not created', 1);
    }
    $size = @filesize($filepath);
    if ($size === false || $size === 0) {
        @unlink($filepath);
        hr1BackupRespond('EMPTY_OUTPUT', 'backup file is empty', 1);
    }
    @chmod($filepath, 0640); // best effort; Windows ignores file modes

    echo 'Backup created: ' . $filename . ' (' . $size . ' bytes)' . "\n";

    [$deleted, $retained] = hr1BackupPurge($dir);
    if ($deleted > 0) {
        echo 'Removed ' . $deleted . ' old backup(s).' . "\n";
    }
    echo 'Done. ' . $retained . ' backup(s) retained.' . "\n";

    hr1BackupLog('OK', 'backup completed: ' . $filename . ' (' . $size . ' bytes)');
    exit(0);
} catch (Throwable $e) {
    if (is_string($optionFile) && $optionFile !== '' && file_exists($optionFile)) {
        @unlink($optionFile);
    }
    if (is_string($filepath) && $filepath !== '' && file_exists($filepath)) {
        @unlink($filepath);
    }
    hr1BackupRespond('UNCAUGHT', get_class($e) . ': ' . $e->getMessage(), 1);
}