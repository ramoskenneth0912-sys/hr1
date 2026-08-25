<?php
/**
 * Marks ONE notification as read — only if it belongs to the logged-in
 * account — then forwards to the notification's link.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$fallback = isHRorManager() ? BASE_URL . '/index.php' : BASE_URL . '/index.php';

$stmt = db()->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$id, (int) $_SESSION['user_id']]);
$notif = $stmt->fetch();

if (!$notif) {
    flash('danger', 'Notification not found.');
    redirect($fallback);
}

db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?')->execute([$id]);

$dest = $fallback;
if (!empty($notif['link']) && str_starts_with($notif['link'], BASE_URL . '/')) {
    $dest = $notif['link'];
}
redirect($dest);
