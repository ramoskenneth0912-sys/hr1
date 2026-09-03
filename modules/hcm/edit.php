<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid employee ID.');
    redirect(BASE_URL . '/modules/hcm/index.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $jobTitle = trim((string) ($_POST['job_title'] ?? ''));
    $hireDate = trim((string) ($_POST['hire_date'] ?? ''));

    if ($firstName === '') {
        $errors[] = 'First name is required.';
    }
    if ($lastName === '') {
        $errors[] = 'Last name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if ($jobTitle === '') {
        $errors[] = 'Job title is required.';
    }
    if ($hireDate === '') {
        $errors[] = 'Hire date is required.';
    }

    if ($errors) {
        $stmt = db()->prepare('SELECT * FROM employees WHERE id = ?');
        $stmt->execute([$id]);
        $employee = $stmt->fetch();
        if (!$employee) {
            flash('danger', 'Employee not found.');
            redirect(BASE_URL . '/modules/hcm/index.php');
        }
        foreach (['first_name', 'last_name', 'email', 'phone', 'department_id',
            'job_title', 'employment_type', 'hire_date', 'salary', 'status'] as $k) {
            if (array_key_exists($k, $_POST)) {
                $employee[$k] = $_POST[$k];
            }
        }
        goto renderEmployeeEdit;
    }

    $stmt = db()->prepare(
        'UPDATE employees SET first_name=?, last_name=?, email=?, phone=?, department_id=?,
         job_title=?, employment_type=?, hire_date=?, status=?, salary=? WHERE id=?'
    );
    $stmt->execute([
        $firstName,
        $lastName,
        $email,
        trim($_POST['phone'] ?? ''),
        $_POST['department_id'] ?: null,
        $jobTitle,
        $_POST['employment_type'],
        $hireDate,
        $_POST['status'],
        $_POST['salary'] !== '' ? (float) $_POST['salary'] : null,
        $id,
    ]);
    flash('success', 'Employee updated.');
    redirect(BASE_URL . '/modules/hcm/view.php?id=' . $id);
}

renderEmployeeEdit:
$stmt = db()->prepare('SELECT * FROM employees WHERE id = ?');
$stmt->execute([$id]);
$employee = $stmt->fetch();
if (!$employee) {
    flash('danger', 'Employee not found.');
    redirect(BASE_URL . '/modules/hcm/index.php');
}

$pageTitle = 'Edit Employee';
$currentModule = 'hcm';
$departments = getDepartments();
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <h1 class="page-title">Edit Employee</h1>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline">← Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger" style="margin-bottom:1rem;">
    <ul style="margin:0;padding-left:1.25rem;">
        <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" class="form-panel fade-in-up" style="animation-delay:.1s">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="form-group">
            <label>Employee No.</label>
            <input type="text" value="<?= e($employee['employee_no']) ?>" disabled>
        </div>
        <div class="form-group">
            <label for="first_name">First Name *</label>
            <input type="text" id="first_name" name="first_name" value="<?= e($employee['first_name']) ?>" required>
        </div>
        <div class="form-group">
            <label for="last_name">Last Name *</label>
            <input type="text" id="last_name" name="last_name" value="<?= e($employee['last_name']) ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" value="<?= e($employee['email']) ?>" required>
        </div>
        <div class="form-group">
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" value="<?= e($employee['phone']) ?>">
        </div>
        <div class="form-group">
            <label for="job_title">Job Title *</label>
            <input type="text" id="job_title" name="job_title" value="<?= e($employee['job_title']) ?>" required>
        </div>
        <div class="form-group">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id">
                <option value="">— Select —</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?= (int) $dept['id'] ?>" <?= $employee['department_id'] == $dept['id'] ? 'selected' : '' ?>>
                    <?= e($dept['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="employment_type">Employment Type</label>
            <select id="employment_type" name="employment_type">
                <?php foreach (['regular','contractual','probationary','part_time'] as $t): ?>
                <option value="<?= $t ?>" <?= $employee['employment_type'] === $t ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="hire_date">Hire Date *</label>
            <input type="date" id="hire_date" name="hire_date" value="<?= e($employee['hire_date']) ?>" required>
        </div>
        <div class="form-group">
            <label for="salary">Salary</label>
            <input type="number" id="salary" name="salary" step="0.01" value="<?= e($employee['salary']) ?>">
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['active','on_leave','terminated','resigned'] as $s): ?>
                <option value="<?= $s ?>" <?= $employee['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Update Employee</button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
