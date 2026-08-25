<?php
/**
 * SETTINGS — account & security settings ONLY (change password).
 * Profile information belongs in My Profile; nothing else lives here.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrf_require();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        flash('danger', 'Your current password is incorrect.');
    } elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        flash('danger', 'New password must be at least 8 characters and contain letters and numbers.');
    } elseif ($new !== $confirm) {
        flash('danger', 'New password and confirmation do not match.');
    } elseif (password_verify($new, $row['password_hash'])) {
        flash('warning', 'New password must be different from your current password.');
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), (int) $_SESSION['user_id']]);
        session_regenerate_id(true);
        csrf_rotate();
        flash('success', 'Password changed successfully.');
    }
    redirect(BASE_URL . '/modules/employee/settings.php');
}

$pageTitle = 'Settings';
$currentModule = 'settings';
$bodyClass = 'page-dashboard';
$user = getCurrentUser();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-subtitle">Account security and preferences</p>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Change Password</h2>
    <form method="post" class="form-panel compact-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-grid">
            <div class="form-group full-width">
                <label for="current_password">Current Password *</label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="new_password">New Password *</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                <small class="panel-desc">At least 8 characters with letters and numbers.</small>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password *</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Update Password</button>
        </div>
    </form>
</section>

<section class="panel fade-in-up" style="animation-delay:.2s">
    <h2>Account</h2>
    <div class="detail-grid">
        <div class="detail-item"><label>Signed in as</label><span><?= e($user['username']) ?> · <?= e(ucfirst($user['role'])) ?></span></div>
    </div>
    <p class="panel-desc">To sign out on this device, use the Logout button in the header. Profile details are managed under My Profile.</p>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
