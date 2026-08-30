<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';

// Logout is a state-changing action: require a valid CSRF token, and only
// allow it via POST (prevents cross-site / GET-triggered forced logout).
csrf_require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/public/jobs.php');
    exit;
}

// Fully invalidate the authenticated session: discard all session data,
// regenerate the session ID so a fixed/pre-existing ID cannot be reused,
// then destroy the session on the server.
$_SESSION = [];
session_unset();
session_regenerate_id(true);
session_destroy();

// Expire the session cookie on the client as well.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

header('Location: ' . BASE_URL . '/public/jobs.php');
exit;
