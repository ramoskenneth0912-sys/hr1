<?php
require_once __DIR__ . '/../../includes/auth.php';
requireNotApplicant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $applicantNo = generateCode('APP', 'applicants', 'applicant_no');

    $stmt = db()->prepare(
        'INSERT INTO applicants (applicant_no, first_name, last_name, email, phone, address,
         position_applied, department_id, status, applied_date, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $applicantNo,
        trim($_POST['first_name']),
        trim($_POST['last_name']),
        trim($_POST['email']),
        trim($_POST['phone'] ?? ''),
        trim($_POST['address'] ?? ''),
        trim($_POST['position_applied']),
        $_POST['department_id'] ?: null,
        $_POST['status'] ?? 'new',
        $_POST['applied_date'],
        trim($_POST['notes'] ?? ''),
    ]);

    flash('success', 'Applicant ' . $applicantNo . ' created successfully.');
    redirect(BASE_URL . '/modules/applicants/index.php');
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
            <label for="position_applied">Position Applied *</label>
            <input type="text" id="position_applied" name="position_applied" required>
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
            <label for="applied_date">Applied Date *</label>
            <input type="date" id="applied_date" name="applied_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['new','screening','interview','offered','hired','rejected'] as $s): ?>
                <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group full-width">
            <label for="address">Address</label>
            <textarea id="address" name="address" rows="2"></textarea>
        </div>
        <div class="form-group full-width">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3"></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Applicant</button>
        <a href="index.php" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
