<?php
$pageTitle = 'Create User Account';
$currentModule = 'users';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_log.php';
requireHRorManager();
requireNotApplicant();

$errors = [];
$old = [];

// Employees that are not yet linked to any user account (only active leaves).
$unlinked = db()->query(
    "SELECT e.id, e.employee_no, e.first_name, e.last_name, e.email, e.job_title,
            d.name AS department_name
     FROM employees e
     LEFT JOIN departments d ON e.department_id = d.id
     LEFT JOIN users u ON u.employee_id = e.id
     WHERE u.id IS NULL AND e.status = 'active'
     ORDER BY e.last_name, e.first_name"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $old = $_POST;
    $employee_id = (int) ($_POST['employee_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'employee';

    if ($employee_id <= 0) $errors[] = 'Please select an employee to link.';
    if ($username === '') $errors[] = 'Username is required.';
    if ($email === '') $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if ($password === '') $errors[] = 'Password is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if (!in_array($role, ['hr', 'manager', 'employee'])) $errors[] = 'Invalid role.';

    if ($employee_id > 0) {
        // Confirm the selected employee exists, is active, and is not already linked.
        $stmt = db()->prepare(
            "SELECT e.id FROM employees e
             LEFT JOIN users u ON u.employee_id = e.id
             WHERE e.id = ? AND e.status = 'active' AND u.id IS NULL"
        );
        $stmt->execute([$employee_id]);
        if (!$stmt->fetch()) {
            $errors[] = 'The selected employee is not available for account creation.';
        }
    }

    if ($username !== '') {
        $stmt = db()->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) $errors[] = 'A user with this username already exists.';
    }

    if ($email !== '') {
        $stmt = db()->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) $errors[] = 'A user with this email already exists.';
    }

    if (empty($errors)) {
        $stmt = db()->prepare(
            "INSERT INTO users (username, email, password_hash, role, employee_id, is_active)
             VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $username,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            $employee_id,
        ]);
        $userId = (int) db()->lastInsertId();

        securityLog('user_account_created', "user_id={$userId} employee_id={$employee_id} role={$role}", (int) ($_SESSION['user_id'] ?? 0));

        flash('success', 'User account created successfully.');
        redirect(BASE_URL . '/modules/users/index.php');
    }
}

// Render chrome only after all redirect-capable logic has finished.
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Create User Account</h1>
        <p class="page-subtitle">Link an employee and grant system access</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Back</a>
</div>

<?php if (empty($unlinked)): ?>
    <div class="panel fade-in-up" style="max-width:600px;animation-delay:.1s">
        <div class="alert alert-info">
            <p>There are no active employees available to link. All active employees already have a system account,
            or there are no employee records yet.</p>
        </div>
        <p style="margin-top:1rem;">
            <a href="<?= BASE_URL ?>/modules/hcm/index.php" class="btn btn-primary">Manage Employees</a>
            <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">Back to Accounts</a>
        </p>
    </div>
    <?php require_once __DIR__ . '/../../includes/footer.php'; exit; ?>
<?php endif; ?>

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
            <label for="employee_id">Linked Employee *</label>
            <select id="employee_id" name="employee_id" required>
                <option value="">— Select Employee —</option>
                <?php foreach ($unlinked as $emp): ?>
                    <option value="<?= (int) $emp['id'] ?>" <?= ((int) ($old['employee_id'] ?? 0)) === (int) $emp['id'] ? 'selected' : '' ?>>
                        <?= e($emp['employee_no'] . ' — ' . $emp['first_name'] . ' ' . $emp['last_name'] . ($emp['job_title'] ? ' (' . $emp['job_title'] . ')' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="username">Username *</label>
            <input type="text" id="username" name="username" value="<?= e($old['username'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Password *</label>
            <input type="password" id="password" name="password" required minlength="6">
        </div>
        <div class="form-group">
            <label for="role">Role *</label>
            <select id="role" name="role" required>
                <option value="employee" <?= (($old['role'] ?? '') === 'employee') ? 'selected' : '' ?>>Employee</option>
                <option value="manager" <?= (($old['role'] ?? '') === 'manager') ? 'selected' : '' ?>>Manager</option>
                <option value="hr" <?= (($old['role'] ?? '') === 'hr') ? 'selected' : '' ?>>HR</option>
            </select>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Account</button>
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
