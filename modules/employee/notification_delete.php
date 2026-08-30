<?php
/**
 * Removes ONE notification from the active bell for the logged-in account —
 * only if it belongs to that account.
 *
 * "Remove" is NON-destructive: the notification is dismissed (hidden from
 * the bell) but its row is kept, so it remains in the permanent
 * "View All Notifications" history. If it was unread, dismissing it also
 * updates the unread count (counts derive from active, unread rows).
 *
 * State-changing: must be submitted via POST with a valid CSRF token, and
 * the row is updated with an owner-scoped predicate so one user can never
 * remove another user's notification.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
requireLogin();

// Removes are state-changing: only ever accept a POST request.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit;
}
csrf_require();

$id = (int) ($_POST['id'] ?? 0);

db()->prepare('UPDATE notifications SET dismissed_at = NOW() WHERE id = ? AND user_id = ? AND dismissed_at IS NULL')
    ->execute([$id, (int) $_SESSION['user_id']]);

// Return to the page the request came from, but only if it is inside this
// application (avoids open-redirect). Fall back to the notification list.
$fallback = BASE_URL . '/modules/employee/notifications.php';
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref !== '' && str_starts_with($ref, BASE_URL . '/')) {
    $fallback = $ref;
}
redirect($fallback);
