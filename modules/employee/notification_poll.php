<?php
/**
 * NOTIFICATION POLL — lightweight read-only endpoint used by the header
 * bell's automatic real-time polling.
 *
 * SECURITY / CORRECTNESS NOTES
 *   - It NEVER changes state, so no CSRF token exchange is performed here
 *     (state-changing actions remain POST + CSRF in the existing forms).
 *   - It requires an authenticated session (requireLogin). The session cookie
 *     is HttpOnly + SameSite=Lax (+ Secure over HTTPS), so a cross-site
 *     request from another origin will NOT carry the session cookie and
 *     therefore cannot read this user's notifications.
 *   - Only ONE payload is returned: the notifications belonging to the
 *     logged-in user. No user-supplied id is ever used in a query, so no
 *     IDOR / cross-user data exposure is possible.
 *   - The response is JSON (never HTML), so it cannot be used to inject
 *     markup into another page via <img>/<script> embedding side-effects.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$data = notification_data($uid);

echo json_encode([
    'count'     => (int) $data['count'],
    'latest_id' => (int) $data['latest_id'],
    'latest_ts' => (string) $data['latest_ts'],
    'html'      => notification_dropdown_html($uid),
]);
