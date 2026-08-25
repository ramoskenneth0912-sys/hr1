<?php
$pageTitle = 'Edit User Account';
$currentModule = 'users';
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
requireNotApplicant();

$user_id = (int) ($_GET['id'] ?? 0);
if (!$user_id) {
    redirect(BASE_URL . '/modules/users/index.php');
}

$departments = getDepartments();
$errors = [];

$stmt = db()->prepare("SELECT u.*, e.first_name, e.last_name, e.employee_no, e.phone, e.job_title, e.department_id, e.status AS employee_status FROM users u LEFT JOIN employees e ON u.employee_id = e.id WHERE u.id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    flash('danger', 'User not found.');
    redirect(BASE_URL . '/modules/users/index.php');
}

$old = [
    'first_name' => $user['first_name'] ?? '',
    'last_name' => $user['last_name'] ?? '',
    'email' => $user['email'] ?? '',
    'job_title' => $user['job_title'] ?? '',
    'department_id' => $user['department_id'] ?? '',
    'phone' => $user['phone'] ?? '',
    'role' => $user['role'] ?? 'employee',
    'employee_status' => $user['employee_status'] ?? 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $old['first_name'] = trim($_POST['first_name'] ?? '');
    $old['last_name'] = trim($_POST['last_name'] ?? '');
    $old['email'] = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $old['job_title'] = trim($_POST['job_title'] ?? '');
    $old['department_id'] = $_POST['department_id'] ?? '';
    $old['phone'] = trim($_POST['phone'] ?? '');
    $old['role'] = $_POST['role'] ?? 'employee';
    $old['employee_status'] = $_POST['employee_status'] ?? 'active';

    if ($old['first_name'] === '') $errors[] = 'First name is required.';
    if ($old['last_name'] === '') $errors[] = 'Last name is required.';
    if ($old['email'] === '') $errors[] = 'Email is required.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (!in_array($old['role'], ['hr', 'manager', 'employee'])) $errors[] = 'Invalid role.';
    if ($password !== '' && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    if ($old['email'] !== $user['email']) {
        $stmt = db()->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$old['email'], $user_id]);
        if ($stmt->fetch()) {
            $errors[] = 'A user with this email already exists.';
        }
    }

    if (empty($errors)) {
        $validStatuses = ['active', 'on_leave', 'terminated', 'resigned'];
        $empStatus = in_array($old['employee_status'], $validStatuses) ? $old['employee_status'] : 'active';

        $stmt = db()->prepare("UPDATE employees SET first_name=?, last_name=?, email=?, phone=?, job_title=?, department_id=?, status=? WHERE id=?");
        $stmt->execute([
            $old['first_name'],
            $old['last_name'],
            $old['email'],
            $old['phone'],
            $old['job_title'],
            $old['department_id'] ?: null,
            $empStatus,
            $user['employee_id'],
        ]);

        if ($password !== '') {
            $stmt = db()->prepare("UPDATE users SET email=?, role=?, password_hash=?, is_active=? WHERE id=?");
            $stmt->execute([
                $old['email'],
                $old['role'],
                password_hash($password, PASSWORD_DEFAULT),
                $empStatus === 'active' ? 1 : 0,
                $user_id,
            ]);
        } else {
            $stmt = db()->prepare("UPDATE users SET email=?, role=?, is_active=? WHERE id=?");
            $stmt->execute([
                $old['email'],
                $old['role'],
                $empStatus === 'active' ? 1 : 0,
                $user_id,
            ]);
        }

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
        <p class="page-subtitle">Update account for <?= e($user['username']) ?></p>
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
            <label>Username</label>
            <input type="text" value="<?= e($user['username']) ?>" disabled>
        </div>
        <div class="form-group">
            <label>Employee No.</label>
            <input type="text" value="<?= e($user['employee_no'] ?? '—') ?>" disabled>
        </div>
        <div class="form-group">
            <label for="first_name">First Name *</label>
            <input type="text" id="first_name" name="first_name" value="<?= e($old['first_name']) ?>" required>
        </div>
        <div class="form-group">
            <label for="last_name">Last Name *</label>
            <input type="text" id="last_name" name="last_name" value="<?= e($old['last_name']) ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" minlength="6" placeholder="Leave blank to keep current">
        </div>
        <div class="form-group">
            <label for="job_title">Position</label>
            <input type="text" id="job_title" name="job_title" value="<?= e($old['job_title']) ?>">
        </div>
        <div class="form-group">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id">
                <option value="">— Select —</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= (int) $dept['id'] ?>" <?= ($old['department_id'] == $dept['id']) ? 'selected' : '' ?>>
                        <?= e($dept['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="phone">Contact Number</label>
            <input type="text" id="phone" name="phone" value="<?= e($old['phone']) ?>">
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
            <label for="employee_status">Employee Status</label>
            <select id="employee_status" name="employee_status">
                <?php foreach (['active','on_leave','terminated','resigned'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($old['employee_status'] === $s) ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Update Account</button>
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
