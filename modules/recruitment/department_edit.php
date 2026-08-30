<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid department ID.');
    redirect(BASE_URL . '/modules/recruitment/departments.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $code = strtoupper(trim($_POST['code']));
    $name = trim($_POST['name']);

    if ($code === '' || $name === '') {
        flash('danger', 'Department code and name are required.');
        redirect(BASE_URL . '/modules/recruitment/department_edit.php?id=' . $id);
    }

    if (!preg_match('/^[A-Z0-9]{1,20}$/', $code)) {
        flash('danger', 'Department code must be 1–20 uppercase letters or digits.');
        redirect(BASE_URL . '/modules/recruitment/department_edit.php?id=' . $id);
    }

    $exists = db()->prepare('SELECT id FROM departments WHERE code = ? AND id != ?');
    $exists->execute([$code, $id]);
    if ($exists->fetch()) {
        flash('danger', 'Another department with that code already exists.');
        redirect(BASE_URL . '/modules/recruitment/department_edit.php?id=' . $id);
    }

    $stmt = db()->prepare('UPDATE departments SET code = ?, name = ? WHERE id = ?');
    $stmt->execute([$code, $name, $id]);
    flash('success', 'Department updated.');
    redirect(BASE_URL . '/modules/recruitment/departments.php');
}

$stmt = db()->prepare('SELECT * FROM departments WHERE id = ?');
$stmt->execute([$id]);
$dept = $stmt->fetch();
if (!$dept) {
    flash('danger', 'Department not found.');
    redirect(BASE_URL . '/modules/recruitment/departments.php');
}

$pageTitle = 'Edit Department';
$currentModule = 'recruitment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <h1 class="page-title">Edit Department</h1>
    <a href="departments.php" class="btn btn-outline">← Back</a>
</div>

<form method="post" class="form-panel fade-in-up" style="animation-delay:.1s">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="form-group">
            <label for="code">Department Code *</label>
            <input type="text" id="code" name="code" required maxlength="20"
                   value="<?= e($dept['code']) ?>" pattern="[A-Za-z0-9]{1,20}"
                   style="text-transform:uppercase;">
            <small style="color:var(--muted);font-size:.8rem;">Uppercase letters and digits only, max 20 characters.</small>
        </div>
        <div class="form-group">
            <label for="name">Department Name *</label>
            <input type="text" id="name" name="name" required maxlength="100"
                   value="<?= e($dept['name']) ?>">
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Update Department</button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
