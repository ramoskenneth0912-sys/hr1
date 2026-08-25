<?php
$pageTitle = 'Create User Account';
$currentModule = 'users';
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
requireNotApplicant();

$departments = getDepartments();
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $old = $_POST;
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $job_title = trim($_POST['job_title'] ?? '');
    $department_id = $_POST['department_id'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'employee';

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name === '') $errors[] = 'Last name is required.';
    if ($email === '') $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if ($password === '') $errors[] = 'Password is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if (!in_array($role, ['hr', 'manager', 'employee'])) $errors[] = 'Invalid role.';

    $stmt = db()->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        $errors[] = 'A user with this email already exists.';
    }

    $stmt = db()->prepare("SELECT id FROM users WHERE username = :username");
    $stmt->execute([':username' => $email]);
    if ($stmt->fetch()) {
        $errors[] = 'A user with this email already exists.';
    }

    if (empty($errors)) {
        $employee_no = generateCode('EMP', 'employees', 'employee_no');

        $stmt = db()->prepare("INSERT INTO employees (employee_no, first_name, last_name, email, phone, department_id, job_title, hire_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active')");
        $stmt->execute([
            $employee_no,
            $first_name,
            $last_name,
            $email,
            $phone,
            $department_id ?: null,
            $job_title,
        ]);
        $employee_id = db()->lastInsertId();

        $stmt = db()->prepare("INSERT INTO users (username, email, password_hash, role, employee_id, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $email,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            $employee_id,
        ]);

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
        <p class="page-subtitle">Add a new employee account</p>
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
            <label for="first_name">First Name *</label>
            <input type="text" id="first_name" name="first_name" value="<?= e($old['first_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="last_name">Last Name *</label>
            <input type="text" id="last_name" name="last_name" value="<?= e($old['last_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email / Username *</label>
            <input type="email" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Password *</label>
            <input type="password" id="password" name="password" required minlength="6">
        </div>
        <div class="form-group">
            <label for="job_title">Position</label>
            <input type="text" id="job_title" name="job_title" value="<?= e($old['job_title'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id">
                <option value="">— Select —</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= (int) $dept['id'] ?>" <?= (($old['department_id'] ?? '') == $dept['id']) ? 'selected' : '' ?>>
                        <?= e($dept['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="phone">Contact Number</label>
            <input type="text" id="phone" name="phone" value="<?= e($old['phone'] ?? '') ?>">
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
