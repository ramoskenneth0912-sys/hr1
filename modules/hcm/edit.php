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
            'job_title', 'employment_type', 'hire_date', 'salary', 'status',
            'branch', 'gender', 'age', 'education_level'] as $k) {
            if (array_key_exists($k, $_POST)) {
                $employee[$k] = $_POST[$k];
            }
        }
        goto renderEmployeeEdit;
    }

    $stmt = db()->prepare(
        'UPDATE employees SET first_name=?, last_name=?, email=?, phone=?, department_id=?,
         job_title=?, employment_type=?, hire_date=?, status=?, salary=?, salary_type=?,
         pay_frequency=?, currency=?, branch=?, gender=?, age=?, education_level=? WHERE id=?'
    );
    $ageValue = ($_POST['age'] ?? '') !== '' ? (int) $_POST['age'] : null;
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
        trim($_POST['salary_type'] ?? '') ?: null,
        trim($_POST['pay_frequency'] ?? '') ?: null,
        trim($_POST['currency'] ?? '') ?: 'PHP',
        trim($_POST['branch'] ?? '') ?: null,
        trim($_POST['gender'] ?? '') ?: null,
        $ageValue,
        trim($_POST['education_level'] ?? '') ?: null,
        $id,
    ]);

    // Notify the employee if their salary was changed.
    $oldSalary = (float) ($employee['salary'] ?? 0);
    $newSalary = $_POST['salary'] !== '' ? (float) $_POST['salary'] : 0;
    if ($newSalary !== $oldSalary) {
        $linkedUser = db()->prepare('SELECT id FROM users WHERE employee_id = ? AND is_active = 1 LIMIT 1');
        $linkedUser->execute([$id]);
        $linkedRow = $linkedUser->fetch();
        if ($linkedRow) {
            if ($newSalary > 0 && $oldSalary === 0) {
                notifyUser(
                    (int) $linkedRow['id'],
                    'Salary Assigned',
                    'Your salary information has been set. View your salary details in My Salary.',
                    BASE_URL . '/modules/employee/salary.php'
                );
            } elseif ($newSalary > 0) {
                notifyUser(
                    (int) $linkedRow['id'],
                    'Salary Updated',
                    'Your salary information has been updated. View your current salary in My Salary.',
                    BASE_URL . '/modules/employee/salary.php'
                );
            } elseif ($newSalary === 0 && $oldSalary > 0) {
                notifyUser(
                    (int) $linkedRow['id'],
                    'Salary Removed',
                    'Your salary information has been removed. Please contact HR for details.',
                    BASE_URL . '/modules/employee/salary.php'
                );
            }
        }
    }

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
            <label for="job_title">Current Role *</label>
            <input type="text" id="job_title" name="job_title" value="<?= e($employee['job_title']) ?>" required>
        </div>
        <div class="form-group">
            <label for="branch">Branch</label>
            <input type="text" id="branch" name="branch" value="<?= e($employee['branch']) ?>">
        </div>
        <div class="form-group">
            <label for="age">Age</label>
            <input type="number" id="age" name="age" min="16" max="99" value="<?= e($employee['age'] !== null ? (string) $employee['age'] : '') ?>">
        </div>
        <div class="form-group">
            <label for="gender">Gender</label>
            <select id="gender" name="gender">
                <option value="">— Select —</option>
                <option value="Female" <?= $employee['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                <option value="Male" <?= $employee['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
            </select>
        </div>
        <div class="form-group">
            <label for="education_level">Education Level</label>
            <input type="text" id="education_level" name="education_level" value="<?= e($employee['education_level']) ?>">
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
            <label for="salary_type">Salary Type</label>
            <select id="salary_type" name="salary_type">
                <option value="">— Select —</option>
                <?php foreach (['monthly', 'hourly', 'daily', 'annual'] as $st): ?>
                <option value="<?= $st ?>" <?= $employee['salary_type'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="pay_frequency">Pay Frequency</label>
            <select id="pay_frequency" name="pay_frequency">
                <option value="">— Select —</option>
                <?php foreach (['monthly', 'semi_monthly', 'weekly', 'bi_weekly'] as $pf): ?>
                <option value="<?= $pf ?>" <?= $employee['pay_frequency'] === $pf ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $pf)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="currency">Currency</label>
            <select id="currency" name="currency">
                <?php foreach (['PHP', 'USD', 'EUR', 'JPY', 'SGD'] as $cur): ?>
                <option value="<?= $cur ?>" <?= ($employee['currency'] ?? 'PHP') === $cur ? 'selected' : '' ?>><?= $cur ?></option>
                <?php endforeach; ?>
            </select>
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
