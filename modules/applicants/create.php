<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $positionApplied = trim((string) ($_POST['position_applied'] ?? ''));
    $appliedDate = trim((string) ($_POST['applied_date'] ?? ''));

    if ($firstName === '') {
        $errors[] = 'First name is required.';
    }
    if ($lastName === '') {
        $errors[] = 'Last name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if ($positionApplied === '') {
        $errors[] = 'Position applied is required.';
    }
    if ($appliedDate === '') {
        $errors[] = 'Applied date is required.';
    }

    if (!$errors) {
        $applicantNo = generateCode('APP', 'applicants', 'applicant_no');

        $stmt = db()->prepare(
            'INSERT INTO applicants (applicant_no, first_name, last_name, email, phone, address,
             position_applied, department_id, status, applied_date, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $applicantNo,
            $firstName,
            $lastName,
            $email,
            trim($_POST['phone'] ?? ''),
            trim($_POST['address'] ?? ''),
            $positionApplied,
            $_POST['department_id'] ?: null,
            $_POST['status'] ?? 'new',
            $appliedDate,
            trim($_POST['notes'] ?? ''),
        ]);

        flash('success', 'Applicant ' . $applicantNo . ' created successfully.');
        redirect(BASE_URL . '/modules/applicants/index.php');
    }
    // On validation failure, fall through and re-render with submitted values.
}

$pageTitle = 'New Applicant';
$currentModule = 'applicants';
$departments = getDepartments();
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <h1 class="page-title">New Applicant</h1>
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
            <label for="position_applied">Position Applied *</label>
            <input type="text" id="position_applied" name="position_applied" required value="<?= e($_POST['position_applied'] ?? '') ?>">
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
            <label for="applied_date">Applied Date *</label>
            <input type="date" id="applied_date" name="applied_date" value="<?= e($_POST['applied_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['new','screening','interview','offered','hired','rejected'] as $s): ?>
                <option value="<?= $s ?>" <?= ($_POST['status'] ?? 'new') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group full-width">
            <label for="address">Address</label>
            <textarea id="address" name="address" rows="2"><?= e($_POST['address'] ?? '') ?></textarea>
        </div>
        <div class="form-group full-width">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3"><?= e($_POST['notes'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Applicant</button>
        <a href="index.php" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
