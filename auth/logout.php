<?php
require_once __DIR__ . '/../includes/session.php';
if (!defined('MAINTENANCE_EXEMPT_PAGE')) {
    define('MAINTENANCE_EXEMPT_PAGE', true);
}
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security_log.php';

// Logout is a state-changing action: require a valid CSRF token, and only
// allow it via POST (prevents cross-site / GET-triggered forced logout).
csrf_require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/public/jobs.php');
    exit;
}

// Record the signed-out session in the audit trail BEFORE destroying it —
// the actor identity lives in $_SESSION until now.
securityLog('LOGOUT', 'User signed out', isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, [
    'username'    => $_SESSION['user_name'] ?? null,
    'role'        => $_SESSION['user_role'] ?? null,
    'module'      => 'auth',
    'status'      => 'success',
]);

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
