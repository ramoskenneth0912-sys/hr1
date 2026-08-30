<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $code = strtoupper(trim($_POST['code']));
    $name = trim($_POST['name']);

    if ($code === '' || $name === '') {
        flash('danger', 'Department code and name are required.');
        redirect(BASE_URL . '/modules/recruitment/department_create.php');
    }

    if (!preg_match('/^[A-Z0-9]{1,20}$/', $code)) {
        flash('danger', 'Department code must be 1–20 uppercase letters or digits.');
        redirect(BASE_URL . '/modules/recruitment/department_create.php');
    }

    $exists = db()->prepare('SELECT id FROM departments WHERE code = ?');
    $exists->execute([$code]);
    if ($exists->fetch()) {
        flash('danger', 'A department with that code already exists.');
        redirect(BASE_URL . '/modules/recruitment/department_create.php');
    }

    $stmt = db()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)');
    $stmt->execute([$code, $name]);
    flash('success', 'Department "' . $name . '" created.');
    redirect(BASE_URL . '/modules/recruitment/departments.php');
}

$pageTitle = 'New Department';
$currentModule = 'recruitment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <h1 class="page-title">New Department</h1>
    <a href="departments.php" class="btn btn-outline">← Back</a>
</div>

<form method="post" class="form-panel fade-in-up" style="animation-delay:.1s">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="form-group">
            <label for="code">Department Code *</label>
            <input type="text" id="code" name="code" required maxlength="20"
                   placeholder="e.g. MKT, OPS, FIN" pattern="[A-Za-z0-9]{1,20}"
                   style="text-transform:uppercase;">
            <small style="color:var(--muted);font-size:.8rem;">Uppercase letters and digits only, max 20 characters.</small>
        </div>
        <div class="form-group">
            <label for="name">Department Name *</label>
            <input type="text" id="name" name="name" required maxlength="100"
                   placeholder="e.g. Marketing, Information Technology">
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create Department</button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
