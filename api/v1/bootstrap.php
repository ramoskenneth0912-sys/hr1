<?php
/**
 * API bootstrap — TRI-M GLOBAL Merchandising Management System (HR1)
 * Shared setup for every /api/v1 request: config, headers, error handling,
 * rate limiting and request-parsing helpers. No HTML output ever.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));          // C:\xampp\htdocs\HR1
define('API_STORAGE', BASE_PATH . '/storage/api');
define('API_RATE_DIR', API_STORAGE . '/rate_limits');
define('API_LOG', API_STORAGE . '/error.log');
define('API_TOKEN_TTL_DAYS', 7);
define('API_RESUME_DIR', BASE_PATH . '/uploads/api_resumes');
define('API_RESUME_REL', 'uploads/api_resumes');
define('API_MAX_UPLOAD_BYTES', 5 * 1024 * 1024);   // 5 MB, matches website form

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/functions.php'; // e(), generateCode() — side-effect free
require_once __DIR__ . '/lib/Response.php';
require_once __DIR__ . '/lib/RateLimit.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/ApiKeyAuth.php';
require_once __DIR__ . '/lib/Validator.php';

// ---- JSON + security headers --------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

/*
 * CORS — restricted allowlist instead of "*".
 * Same-origin requests (the website's own pages) never send a cross-origin
 * check and work regardless. Cross-origin calls are only allowed from the
 * origins listed below.
 *   - Production: ONLY the HR1_API_ALLOWED_ORIGINS list (comma-separated).
 *     When it is not configured, cross-origin CORS is disabled entirely —
 *     the localhost/development origins are never emitted in production.
 *   - Development/staging: local Vite dev-server origins are added as
 *     conveniences alongside the HR1_API_ALLOWED_ORIGINS optional override.
 */
$envOrigins = trim((string) (getenv('HR1_API_ALLOWED_ORIGINS') ?: ''));
if (HR1_IS_PRODUCTION && $envOrigins === '') {
    error_log('HR1 API: HR1_API_ALLOWED_ORIGINS is not configured in production; cross-origin CORS is disabled.');
}
$devOrigins = HR1_IS_PRODUCTION ? '' : ',http://localhost,https://localhost,http://127.0.0.1,https://127.0.0.1,http://localhost:5173,http://127.0.0.1:5173';

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $o): string => rtrim(strtolower(trim($o)), '/'),
    explode(',', $envOrigins . $devOrigins)
)));
$requestOrigin = strtolower(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')));
if ($requestOrigin !== '' && in_array(rtrim($requestOrigin, '/'), $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- Ensure writable storage ---------------------------------------------
foreach ([API_STORAGE, API_RATE_DIR, API_RESUME_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

// ---- Errors become JSON, never HTML --------------------------------------
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    @file_put_contents(
        API_LOG,
        sprintf("[%s] %s in %s:%d\n%s\n\n", date('c'), get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()),
        FILE_APPEND
    );
    Response::serverError('An unexpected server error occurred.');
});

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

// ---- Global per-IP rate limit --------------------------------------------
RateLimit::attempt('ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 300, 900)
    or Response::tooManyRequests('Too many requests. Please slow down.');

// ---- Request helpers ------------------------------------------------------
/** Request body as array: JSON for JSON requests, $_POST otherwise. */
function api_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($ctype, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        $body = is_array($decoded) ? $decoded : [];
    } else {
        $body = $_POST;
    }
    return $body;
}

function api_query(string $key, ?string $default = null): ?string
{
    $v = $_GET[$key] ?? $default;
    return ($v === null || $v === '') ? null : trim((string) $v);
}

/** Path after /api/v1/, normalised (works with or without mod_rewrite). */
function route_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $pos = stripos($uri, '/api/v1');
    $path = $pos !== false ? substr($uri, $pos + strlen('/api/v1')) : $uri;
    $path = trim($path, '/');
    if (str_starts_with($path, 'index.php')) {
        $path = substr($path, strlen('index.php'));
    }
    return trim($path, '/');
}
