<?php
/**
 * NOTIFICATIONS — the complete notification list for the logged-in account.
 * The header bell shows only an unread count + small preview; the full
 * content lives here (single source of truth).
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    csrf_require();
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')
        ->execute([(int) $_SESSION['user_id']]);
    flash('success', 'All notifications marked as read.');
    redirect(BASE_URL . '/modules/employee/notifications.php');
}

$pageTitle = 'Notifications';
$currentModule = 'notifications';
$bodyClass = 'page-dashboard';
$uid = (int) $_SESSION['user_id'];

$stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
$stmt->execute([$uid]);
$notifications = $stmt->fetchAll();

$unreadCount = count(array_filter($notifications, fn ($n) => !(int) $n['is_read']));

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Notifications</h1>
        <p class="page-subtitle"><?= $unreadCount > 0 ? $unreadCount . ' unread notification' . ($unreadCount === 1 ? '' : 's') : 'You are all caught up' ?></p>
    </div>
    <div class="page-actions">
        <?php if ($unreadCount > 0): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-outline btn-sm">Mark all read</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <?php if (empty($notifications)): ?>
    <p class="data-table empty" style="display:block;text-align:center;padding:2rem;">No notifications yet.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Message</th>
                <th>Received</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($notifications as $n): ?>
            <tr>
                <td><?= $n['is_read'] ? e($n['title']) : '<strong>' . e($n['title']) . '</strong>' ?></td>
                <td><?= e($n['message'] ?? '') ?></td>
                <td><?= formatDate($n['created_at'] ?? null) ?></td>
                <td><span class="badge <?= $n['is_read'] ? 'badge-secondary' : 'badge-warning' ?>"><?= $n['is_read'] ? 'Read' : 'Unread' ?></span></td>
                <td>
                    <?php if (!$n['is_read']): ?>
                    <form method="post" action="<?= BASE_URL ?>/modules/employee/notification_read.php" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline">Mark read</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
