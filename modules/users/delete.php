<?php
$pageTitle = 'Deactivate User Account';
$currentModule = 'users';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_log.php';
requireHRorManager();
requireNotApplicant();

$user_id = (int) ($_GET['id'] ?? 0);
if (!$user_id) {
    redirect(BASE_URL . '/modules/users/index.php');
}

$stmt = db()->prepare(
    "SELECT u.*, e.first_name, e.last_name FROM users u
     LEFT JOIN employees e ON u.employee_id = e.id WHERE u.id = ?"
);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    flash('danger', 'User not found.');
    redirect(BASE_URL . '/modules/users/index.php');
}

$selfAccount = (int) $user['id'] === (int) ($_SESSION['user_id'] ?? 0);
$alreadyInactive = ((int) ($user['is_active'] ?? 1)) === 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    csrf_require();

    if ($selfAccount) {
        flash('danger', 'You cannot deactivate your own account.');
        redirect(BASE_URL . '/modules/users/index.php');
    }

    // Deactivate only the account — never delete the linked employee record.
    db()->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$user_id]);
    securityLog('USER_DEACTIVATED', "Deactivated account \"{$user['username']}\"", (int) ($_SESSION['user_id'] ?? 0), [
        'module'      => 'users',
        'target_type' => 'user',
        'target_id'   => $user_id,
        'status'      => 'success',
    ]);

    flash('success', 'User account deactivated. The account will no longer be able to sign in.');
    redirect(BASE_URL . '/modules/users/index.php');
}

// Render chrome only after all redirect-capable logic has finished.
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Deactivate User Account</h1>
        <p class="page-subtitle">Revoke system access without deleting records</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Back</a>
</div>

<div class="panel fade-in-up" style="max-width:600px;animation-delay:.1s">
    <?php if ($alreadyInactive): ?>
        <div class="alert alert-info">
            <p>This account is already inactive.</p>
        </div>
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Back</a>
    <?php elseif ($selfAccount): ?>
        <div class="alert alert-danger">
            <p>You cannot deactivate your own account.</p>
        </div>
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Back</a>
    <?php else: ?>
        <div class="alert alert-danger">
            <p>Deactivate the account for <strong><?= e($user['username']) ?></strong>?
            The user will no longer be able to sign in. No employee or data records are deleted, and the account can be reactivated later.</p>
        </div>

        <form method="post" style="margin-top:1rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="confirm" value="yes">
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Deactivate Account</button>
                <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
