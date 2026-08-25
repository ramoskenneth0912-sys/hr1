<?php
$pageTitle = 'Delete User Account';
$currentModule = 'users';
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
requireNotApplicant();

$user_id = (int) ($_GET['id'] ?? 0);
if (!$user_id) {
    redirect(BASE_URL . '/modules/users/index.php');
}

$stmt = db()->prepare("SELECT u.*, e.first_name, e.last_name FROM users u LEFT JOIN employees e ON u.employee_id = e.id WHERE u.id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    flash('danger', 'User not found.');
    redirect(BASE_URL . '/modules/users/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    $employee_id = $user['employee_id'];

    $stmt = db()->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user_id]);

    if ($employee_id) {
        $stmt = db()->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
    }

    flash('success', 'User account deleted successfully.');
    redirect(BASE_URL . '/modules/users/index.php');
}

// Render chrome only after all redirect-capable logic has finished.
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Delete User Account</h1>
        <p class="page-subtitle">This action cannot be undone</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Back</a>
</div>

<div class="panel fade-in-up" style="max-width:600px;animation-delay:.1s">
    <div class="alert alert-danger">
        <p>Are you sure you want to delete <strong><?= e($user['username']) ?></strong>?
        This will permanently remove the user account<?php if ($user['first_name']): ?> and employee record for <?= e($user['first_name'] . ' ' . $user['last_name']) ?><?php endif; ?>.</p>
    </div>

    <form method="post" style="margin-top:1rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="confirm" value="yes">
        <div class="btn-group">
            <button type="submit" class="btn btn-primary">Yes, Delete</button>
            <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
