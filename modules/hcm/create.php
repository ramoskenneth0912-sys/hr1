<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $employeeNo = generateCode('EMP', 'employees', 'employee_no');

    $stmt = db()->prepare(
        'INSERT INTO employees (employee_no, first_name, last_name, email, phone, department_id,
         job_title, employment_type, hire_date, status, salary) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $employeeNo,
        trim($_POST['first_name']),
        trim($_POST['last_name']),
        trim($_POST['email']),
        trim($_POST['phone'] ?? ''),
        $_POST['department_id'] ?: null,
        trim($_POST['job_title']),
        $_POST['employment_type'],
        $_POST['hire_date'],
        $_POST['status'] ?? 'active',
        $_POST['salary'] !== '' ? (float) $_POST['salary'] : null,
    ]);

    $employeeId = (int) db()->lastInsertId();

    db()->prepare(
        'INSERT INTO employment_history (employee_id, event_type, event_date, description, recorded_by)
         VALUES (?, "hire", ?, ?, "System")'
    )->execute([
        $employeeId,
        $_POST['hire_date'],
        'Employee hired as ' . trim($_POST['job_title']),
    ]);

    flash('success', 'Employee ' . $employeeNo . ' created.');
    redirect(BASE_URL . '/modules/hcm/index.php');
}

$pageTitle = 'New Employee';
$currentModule = 'hcm';
$departments = getDepartments();
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <h1 class="page-title">New Employee</h1>
    <a href="index.php" class="btn btn-outline">← Back</a>
</div>

<form method="post" class="form-panel fade-in-up" style="animation-delay:.1s">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="form-group">
            <label for="first_name">First Name *</label>
            <input type="text" id="first_name" name="first_name" required>
        </div>
        <div class="form-group">
            <label for="last_name">Last Name *</label>
            <input type="text" id="last_name" name="last_name" required>
        </div>
        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" required>
        </div>
        <div class="form-group">
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone">
        </div>
        <div class="form-group">
            <label for="job_title">Job Title *</label>
            <input type="text" id="job_title" name="job_title" required>
        </div>
        <div class="form-group">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id">
                <option value="">— Select —</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?= (int) $dept['id'] ?>"><?= e($dept['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="employment_type">Employment Type</label>
            <select id="employment_type" name="employment_type">
                <?php foreach (['regular','contractual','probationary','part_time'] as $t): ?>
                <option value="<?= $t ?>"><?= ucfirst(str_replace('_', ' ', $t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="hire_date">Hire Date *</label>
            <input type="date" id="hire_date" name="hire_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
            <label for="salary">Salary</label>
            <input type="number" id="salary" name="salary" step="0.01" min="0">
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['active','on_leave','terminated','resigned'] as $s): ?>
                <option value="<?= $s ?>"><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Employee</button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
