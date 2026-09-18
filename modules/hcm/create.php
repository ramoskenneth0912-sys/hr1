<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

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

    // Preserve the existing email uniqueness rule (skip on validation failure).
    if (!$errors) {
        $dup = db()->prepare('SELECT id FROM employees WHERE email = ?');
        $dup->execute([$email]);
        if ($dup->fetch()) {
            $errors[] = 'That email address is already in use.';
        }
    }

    if ($errors) {
        goto renderEmployeeCreate;
    }

    $employeeNo = generateCode('E', 'employees', 'employee_no', 3);

    $stmt = db()->prepare(
        'INSERT INTO employees (employee_no, first_name, last_name, email, phone, department_id,
         job_title, employment_type, hire_date, status, salary, salary_type, pay_frequency, currency)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $employeeNo,
        $firstName,
        $lastName,
        $email,
        trim($_POST['phone'] ?? ''),
        $_POST['department_id'] ?: null,
        $jobTitle,
        $_POST['employment_type'],
        $hireDate,
        $_POST['status'] ?? 'active',
        $_POST['salary'] !== '' ? (float) $_POST['salary'] : null,
        trim($_POST['salary_type'] ?? '') ?: null,
        trim($_POST['pay_frequency'] ?? '') ?: null,
        trim($_POST['currency'] ?? '') ?: 'PHP',
    ]);

    $employeeId = (int) db()->lastInsertId();

    db()->prepare(
        'INSERT INTO employment_history (employee_id, event_type, event_date, description, recorded_by)
         VALUES (?, "hire", ?, ?, "System")'
    )->execute([
        $employeeId,
        $hireDate,
        'Employee hired as ' . $jobTitle,
    ]);

    // Notify the employee if salary was assigned during creation.
    $newSalary = $_POST['salary'] !== '' ? (float) $_POST['salary'] : 0;
    if ($newSalary > 0) {
        $linkedUser = db()->prepare('SELECT id FROM users WHERE employee_id = ? AND is_active = 1 LIMIT 1');
        $linkedUser->execute([$employeeId]);
        $linkedRow = $linkedUser->fetch();
        if ($linkedRow) {
            notifyUser(
                (int) $linkedRow['id'],
                'Salary Assigned',
                'Your salary information has been set. View your salary details in My Salary.',
                BASE_URL . '/modules/employee/salary.php'
            );
        }
    }

    flash('success', 'Employee ' . $employeeNo . ' created.');
    redirect(BASE_URL . '/modules/hcm/index.php');
}

renderEmployeeCreate:
$pageTitle = 'New Employee';
$currentModule = 'hcm';
$departments = getDepartments();
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <h1 class="page-title">New Employee</h1>
    <p class="page-subtitle">Create an employee &amp; employment record</p>
    <a href="index.php" class="btn btn-outline">← Back</a>
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
            <label for="first_name">First Name *</label>
            <input type="text" id="first_name" name="first_name" required value="<?= e($_POST['first_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="last_name">Last Name *</label>
            <input type="text" id="last_name" name="last_name" required value="<?= e($_POST['last_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" value="<?= e($_POST['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="job_title">Current Role *</label>
            <input type="text" id="job_title" name="job_title" required value="<?= e($_POST['job_title'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id">
                <option value="">— Select —</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?= (int) $dept['id'] ?>" <?= ((int) ($_POST['department_id'] ?? 0)) === (int) $dept['id'] ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="employment_type">Employment Type</label>
            <select id="employment_type" name="employment_type">
                <?php foreach (['regular','contractual','probationary','part_time'] as $t): ?>
                <option value="<?= $t ?>" <?= ($_POST['employment_type'] ?? 'regular') === $t ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="hire_date">Hire Date *</label>
            <input type="date" id="hire_date" name="hire_date" value="<?= e($_POST['hire_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="form-group">
            <label for="salary">Salary</label>
            <input type="number" id="salary" name="salary" step="0.01" min="0" value="<?= e($_POST['salary'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="salary_type">Salary Type</label>
            <select id="salary_type" name="salary_type">
                <option value="">— Select —</option>
                <?php foreach (['monthly', 'hourly', 'daily', 'annual'] as $st): ?>
                <option value="<?= $st ?>" <?= ($_POST['salary_type'] ?? '') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="pay_frequency">Pay Frequency</label>
            <select id="pay_frequency" name="pay_frequency">
                <option value="">— Select —</option>
                <?php foreach (['monthly', 'semi_monthly', 'weekly', 'bi_weekly'] as $pf): ?>
                <option value="<?= $pf ?>" <?= ($_POST['pay_frequency'] ?? '') === $pf ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $pf)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="currency">Currency</label>
            <select id="currency" name="currency">
                <?php foreach (['PHP', 'USD', 'EUR', 'JPY', 'SGD'] as $cur): ?>
                <option value="<?= $cur ?>" <?= ($_POST['currency'] ?? 'PHP') === $cur ? 'selected' : '' ?>><?= $cur ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['active','on_leave','terminated','resigned'] as $s): ?>
                <option value="<?= $s ?>" <?= ($_POST['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Employee</button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
