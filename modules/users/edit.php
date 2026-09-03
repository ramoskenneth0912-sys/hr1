<?php
$pageTitle = 'Edit User Account';
$currentModule = 'users';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_log.php';
requireHRorManager();
requireNotApplicant();

$user_id = (int) ($_GET['id'] ?? 0);
if (!$user_id) {
    redirect(BASE_URL . '/modules/users/index.php');
}

$errors = [];

$stmt = db()->prepare(
    "SELECT u.*, e.first_name, e.last_name, e.employee_no, e.job_title,
            d.name AS department_name
     FROM users u
     LEFT JOIN employees e ON u.employee_id = e.id
     LEFT JOIN departments d ON e.department_id = d.id
     WHERE u.id = ?"
);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    flash('danger', 'User not found.');
    redirect(BASE_URL . '/modules/users/index.php');
}

$old = [
    'username' => $user['username'],
    'email' => $user['email'],
    'role' => $user['role'] ?? 'employee',
    'is_active' => (int) ($user['is_active'] ?? 1),
];

$selfAccount = (int) ($user['id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $old['username'] = trim($_POST['username'] ?? '');
    $old['email'] = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $old['role'] = $_POST['role'] ?? 'employee';
    if ($selfAccount) {
        // Disabled select is not submitted; preserve current status for your own account.
        $old['is_active'] = (int) ($user['is_active'] ?? 1);
    } else {
        $old['is_active'] = (isset($_POST['is_active']) && (int) $_POST['is_active'] === 1) ? 1 : 0;
    }

    if ($old['username'] === '') $errors[] = 'Username is required.';
    if ($old['email'] === '') $errors[] = 'Email is required.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (!in_array($old['role'], ['hr', 'manager', 'employee'])) $errors[] = 'Invalid role.';
    if ($password !== '' && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    // Never allow disabling or locking yourself out.
    if ($selfAccount && $old['is_active'] === 0) {
        $errors[] = 'You cannot deactivate your own account.';
    }

    if ($old['username'] !== $user['username']) {
        $stmt = db()->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$old['username'], $user_id]);
        if ($stmt->fetch()) $errors[] = 'A user with this username already exists.';
    }

    if ($old['email'] !== $user['email']) {
        $stmt = db()->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$old['email'], $user_id]);
        if ($stmt->fetch()) $errors[] = 'A user with this email already exists.';
    }

    if (empty($errors)) {
        if ($password !== '') {
            $stmt = db()->prepare("UPDATE users SET username=?, email=?, role=?, is_active=?, password_hash=? WHERE id=?");
            $stmt->execute([
                $old['username'],
                $old['email'],
                $old['role'],
                $old['is_active'],
                password_hash($password, PASSWORD_DEFAULT),
                $user_id,
            ]);
        } else {
            $stmt = db()->prepare("UPDATE users SET username=?, email=?, role=?, is_active=? WHERE id=?");
            $stmt->execute([
                $old['username'],
                $old['email'],
                $old['role'],
                $old['is_active'],
                $user_id,
            ]);
        }

        securityLog('user_account_updated', "user_id={$user_id} role={$old['role']} active={$old['is_active']}", (int) ($_SESSION['user_id'] ?? 0));

        flash('success', 'User account updated successfully.');
        redirect(BASE_URL . '/modules/users/index.php');
    }
}

// Render chrome only after all redirect-capable logic has finished.
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Edit User Account</h1>
        <p class="page-subtitle">Manage system access for <?= e($user['username']) ?></p>
    </div>
    <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Back</a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <p><?= e($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" class="form-panel fade-in-up" style="animation-delay:.1s">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="form-group">
            <label for="username">Username *</label>
            <input type="text" id="username" name="username" value="<?= e($old['username']) ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required>
        </div>
        <div class="form-group">
            <label>Linked Employee</label>
            <?php if (!empty($user['first_name'])): ?>
                <input type="text" value="<?= e($user['employee_no'] . ' — ' . $user['first_name'] . ' ' . $user['last_name'] . ($user['job_title'] ? ' (' . $user['job_title'] . ')' : '')) ?>" disabled>
            <?php else: ?>
                <input type="text" value="— No linked employee —" disabled>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" minlength="6" placeholder="Leave blank to keep current">
        </div>
        <div class="form-group">
            <label for="role">Role *</label>
            <select id="role" name="role" required>
                <option value="employee" <?= ($old['role'] === 'employee') ? 'selected' : '' ?>>Employee</option>
                <option value="manager" <?= ($old['role'] === 'manager') ? 'selected' : '' ?>>Manager</option>
                <option value="hr" <?= ($old['role'] === 'hr') ? 'selected' : '' ?>>HR</option>
            </select>
        </div>
        <div class="form-group">
            <label for="is_active">Account Status</label>
            <select id="is_active" name="is_active" <?= $selfAccount ? 'disabled' : '' ?>>
                <option value="1" <?= $old['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= $old['is_active'] === 0 ? 'selected' : '' ?>>Inactive</option>
            </select>
            <?php if ($selfAccount): ?>
                <p class="field-hint" style="font-size:.8rem;color:var(--muted);margin-top:.25rem;">You cannot deactivate your own account.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Update Account</button>
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
