<?php
/**
 * Removes ALL active notifications for the currently logged-in account only.
 *
 * "Remove All" is NON-destructive: notifications are dismissed (hidden from
 * the bell) but their rows are kept, so they remain in the permanent
 * "View All Notifications" history. The unread count resets to 0 because
 * counts derive from active, unread rows.
 *
 * The user_id is taken from the server-side session — never from the
 * browser — and the UPDATE is owner-scoped so one account can never remove
 * another account's notifications.
 *
 * State-changing: must be submitted via POST with a valid CSRF token.
 * GET is never allowed to perform this action.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit;
}
csrf_require();

db()->prepare('UPDATE notifications SET dismissed_at = NOW() WHERE user_id = ? AND dismissed_at IS NULL')
    ->execute([(int) $_SESSION['user_id']]);

// Return to the page the request came from, but only if it is inside this
// application (avoids open-redirect). Fall back to the notification list.
$fallback = BASE_URL . '/modules/employee/notifications.php';
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref !== '' && str_starts_with($ref, BASE_URL . '/')) {
    $fallback = $ref;
}
redirect($fallback);
