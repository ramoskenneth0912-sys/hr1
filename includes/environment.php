<?php
/**
 * HR1 environment bootstrap.
 *
 * Loaded FIRST by includes/functions.php and config/database.php so every web
 * request, CLI script and API call shares one source of truth for:
 *   - .env loading            (optional; real environment variables always win)
 *   - APP_ENV                 (development | staging | production)
 *   - trusted HTTPS detection (reverse-proxy aware)
 *   - the trusted absolute base URL used for email links (APP_URL)
 *   - production runtime safeguards: error display off, a generic 500 page,
 *     automatic HTTP -> HTTPS enforcement and minimum-config validation.
 *
 * This file defines only environment helpers and constants. It never outputs
 * HTML for normal errors and never reveals configuration values or secrets.
 */

if (defined('HR1_ENVIRONMENT_LOADED')) {
    return;
}
define('HR1_ENVIRONMENT_LOADED', true);

/**
 * Load KEY=VALUE lines from a dotenv file, if present at the given path.
 * Real environment variables always take precedence (a .env file can only
 * FILL gaps, never override a live value), and placeholder values such as
 * `your_db_password` / `put_your_token_here` are ignored so a committed
 * .env.example can never act as live configuration.
 *
 * @internal
 */
function _hr1_load_dotenv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $line) !== 1) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key === '') {
            continue;
        }
        if (getenv($key) !== false) {
            continue; // never override a real environment value
        }
        if (str_starts_with(strtolower($value), 'your_')
            || str_starts_with(strtolower($value), 'put_')
            || str_starts_with(strtolower($value), 'change_')) {
            continue; // placeholder template value — never live config
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

_hr1_load_dotenv(dirname(__DIR__) . '/.env');

define('APP_ENV', strtolower(trim((string) (getenv('APP_ENV') ?: 'development'))));
define('HR1_IS_PRODUCTION', in_array(APP_ENV, ['production', 'prod'], true));

/**
 * Read an environment value. Returns null when the key is unset or blank
 * after trimming, making it safe to distinguish "not configured" from "set".
 */
function hr1_env(?string $key): ?string
{
    if ($key === null || $key === '') {
        return null;
    }
    $v = getenv($key);
    if ($v === false) {
        return null;
    }
    $v = trim($v);
    return $v === '' ? null : $v;
}

/** True when this process runs in production mode. */
function hr1_is_production(): bool
{
    return HR1_IS_PRODUCTION;
}

/**
 * True when the TLS-terminating request came from a configured, trusted proxy.
 * Trust is based on the peer's actual remote address (HR1_TRUSTED_PROXIES),
 * never on the presence of an X-Forwarded-* header alone.
 */
function hr1_proxy_peer_trusted(): bool
{
    static $trusted = null;
    if ($trusted === null) {
        $trusted = false;
        $list = hr1_env('HR1_TRUSTED_PROXIES');
        if ($list !== null) {
            $peer = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            if ($peer !== '') {
                foreach (preg_split('/[\s,]+/', $list) as $entry) {
                    if ($entry !== '' && $entry === $peer) {
                        $trusted = true;
                        break;
                    }
                }
            }
        }
    }
    return $trusted;
}

/**
 * HTTPS detection that is safe behind a TLS-terminating reverse proxy.
 * The X-Forwarded-Proto header is honored ONLY when the request's peer IP is
 * listed in HR1_TRUSTED_PROXIES.
 */
function hr1_is_https(): bool
{
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    if (hr1_proxy_peer_trusted()) {
        $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($proto === 'https') {
            return true;
        }
    }
    return false;
}

/**
 * Whether a command is reachable via the OS PATH. Used only to check for
 * optional binaries (pdf/word converters, Tesseract OCR); never runs user
 * input.
 */
function hr1_command_on_path(string $command): bool
{
    $code = 1;
    $output = [];
    $safe = escapeshellarg($command);
    if (PHP_OS_FAMILY === 'Windows') {
        exec('where ' . $safe . ' 2>NUL', $output, $code);
    } else {
        exec('command -v ' . $safe . ' 2>/dev/null', $output, $code);
    }
    return $code === 0 && !empty(array_filter($output));
}

/**
 * Server hostname from HTTP_HOST/SERVER_NAME, stripped of scheme, path and
 * query, and restricted to hostname-safe characters. Returns '' when absent.
 * Used ONLY to build redirect targets / dev-relative links.
 */
function hr1_hostname(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = trim((string) ($_SERVER['SERVER_NAME'] ?? ''));
    }
    if ($host === '') {
        return '';
    }
    $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host);
    $host = preg_replace('#/.*$#', '', $host);
    $host = preg_replace('/[?#].*$/', '', $host);
    $host = strtolower($host);
    if ($host !== '' && preg_match('/^[a-z0-9.:\[\]-]+$/', $host) === 1) {
        return $host;
    }
    return '';
}

/**
 * Base URL path prefix (e.g. "/HR1") shared by every page. Honors an HR1_BASE_URL
 * that is explicitly set to empty (domain/VirtualHost root).
 */
function hr1_base_path(): string
{
    if (defined('BASE_URL')) {
        $base = trim((string) BASE_URL);
        return $base === '' ? '' : rtrim($base, '/');
    }
    $baseUrlEnv = getenv('HR1_BASE_URL');
    if ($baseUrlEnv === false) {
        return '/HR1';
    }
    $baseUrlEnv = trim($baseUrlEnv);
    return $baseUrlEnv === '' ? '' : rtrim($baseUrlEnv, '/');
}

/**
 * Canonical trusted absolute base URL (no trailing slash) for building email
 * links and public URLs. In production APP_URL MUST be configured; the function
 * never falls back to an attacker-controllable Host header there (returns '').
 */
function hr1_absolute_base_url(): string
{
    $appUrl = hr1_env('APP_URL');
    if ($appUrl !== null) {
        return rtrim(preg_replace('/[?#].*$/', '', $appUrl), '/');
    }
    if (HR1_IS_PRODUCTION) {
        return '';
    }
    $host = hr1_hostname();
    if ($host === '') {
        return '';
    }
    return (hr1_is_https() ? 'https' : 'http') . '://' . $host . hr1_base_path();
}

/**
 * Names of the production settings that are missing or invalid. Values are
 * NEVER included here — callers log names only, never secrets.
 */
function hr1_production_config_errors(): array
{
    $errors = [];

    $appUrl = hr1_env('APP_URL');
    if ($appUrl === null) {
        $errors[] = 'APP_URL';
    } elseif (substr(strtolower($appUrl), 0, 8) !== 'https://') {
        $errors[] = 'APP_URL (must start with https://)';
    }

    if (getenv('HR1_BASE_URL') === false) {
        $errors[] = 'HR1_BASE_URL';
    }

    foreach (['HR1_DB_HOST', 'HR1_DB_NAME', 'HR1_DB_USER', 'HR1_DB_PASS'] as $name) {
        if (hr1_env($name) === null) {
            $errors[] = $name;
        }
    }
    if (hr1_env('HR1_DB_USER') === 'root') {
        $errors[] = 'HR1_DB_USER (must not be root)';
    }

    return $errors;
}

/**
 * Fail fast when production mode is missing required configuration. Logs the
 * missing item NAMES to the server error log and stops the request with a
 * generic, secret-free response. No-op in development/staging and in CLI.
 */
function hr1_assert_production_config(): void
{
    if (!HR1_IS_PRODUCTION || PHP_SAPI === 'cli') {
        return;
    }
    $errors = hr1_production_config_errors();
    if ($errors === []) {
        return;
    }
    foreach ($errors as $name) {
        error_log('HR1 PRODUCTION CONFIG ERROR: missing or invalid: ' . $name);
    }
    http_response_code(503);
    exit('The application is not configured for production. Please contact the administrator.');
}

/**
 * Redirect the current request to HTTPS in production. Handled at the very
 * start of every request (before sessions, auth or config), so credentials are
 * never processed over plain HTTP. Proxy-aware (see hr1_is_https()).
 */
function hr1_enforce_https(): void
{
    if (!HR1_IS_PRODUCTION || PHP_SAPI === 'cli' || hr1_is_https()) {
        return;
    }
    $host = hr1_hostname();
    $uri  = str_replace(["\r", "\n"], '', (string) ($_SERVER['REQUEST_URI'] ?? '/'));
    if ($host === '' || $uri === '') {
        return;
    }
    $uri = preg_replace('#^https?://[^/]+#i', '', $uri) ?: '/';
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: https://' . $host . $uri);
    exit;
}

// ----- Production runtime safeguards --------------------------------------
if (HR1_IS_PRODUCTION) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');

    $errorLog = hr1_env('HR1_ERROR_LOG');
    if ($errorLog !== null) {
        ini_set('error_log', $errorLog);
    }

    if (PHP_SAPI !== 'cli') {
        // Generic, secret-free response for any uncaught error. The API
        // front controller installs its own JSON handler after this loads.
        set_exception_handler(function (Throwable $e): void {
            error_log('[HR1] uncaught ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'An unexpected error occurred. Please try again later.';
            }
            exit(1);
        });

        // HTTP -> HTTPS enforcement for every web request, before anything else.
        hr1_enforce_https();
    }
}