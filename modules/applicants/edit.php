<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid applicant ID.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $errors = [];

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

    if ($errors) {
        $stmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
        $stmt->execute([$id]);
        $applicant = $stmt->fetch();
        if (!$applicant) {
            flash('danger', 'Applicant not found.');
            redirect(BASE_URL . '/modules/applicants/index.php');
        }
        foreach (['first_name', 'last_name', 'email', 'phone', 'position_applied',
            'department_id', 'job_posting_id', 'status', 'applied_date', 'address', 'notes'] as $k) {
            if (array_key_exists($k, $_POST)) {
                $applicant[$k] = $_POST[$k];
            }
        }
        goto renderEditForm;
    }

    // Server-side whitelist — never trust the frontend to pick an arbitrary status.
    $newStatus = trim($_POST['status'] ?? '');
    $allowedStatuses = ['new', 'screening', 'shortlisted', 'accepted', 'passed_screening', 'interview', 'offered', 'hired', 'rejected'];
    if (!in_array($newStatus, $allowedStatuses, true)) {
        flash('danger', 'Invalid status value.');
        redirect(BASE_URL . '/modules/applicants/index.php');
    }

    $oldStmt = db()->prepare('SELECT status FROM applicants WHERE id = ?');
    $oldStmt->execute([$id]);
    $oldStatus = ($prev = $oldStmt->fetch()) ? $prev['status'] : null;

    $stmt = db()->prepare(
        'UPDATE applicants SET first_name=?, last_name=?, email=?, phone=?, address=?,
         position_applied=?, department_id=?, job_posting_id=?, status=?, applied_date=?, notes=? WHERE id=?'
    );
    $stmt->execute([
        $firstName,
        $lastName,
        $email,
        trim($_POST['phone'] ?? ''),
        trim($_POST['address'] ?? ''),
        $positionApplied,
        $_POST['department_id'] ?: null,
        $_POST['job_posting_id'] ?: null,
        $newStatus,
        $appliedDate,
        trim($_POST['notes'] ?? ''),
        $id,
    ]);

    $freshStmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
    $freshStmt->execute([$id]);
    $fresh = $freshStmt->fetch();

    // Notify the applicant's account when HR moves their application forward.
    if ($oldStatus !== $newStatus) {
        $labels = applicationStatuses();
        notifyUser(
            applicantUserId($fresh),
            'Application update',
            'Your application for ' . $fresh['position_applied'] . ' is now "'
                . ($labels[$fresh['status']] ?? ucfirst($fresh['status'])) . '".',
            BASE_URL . '/modules/applicant/dashboard.php'
        );
    }

    // Send the Online Examination email when HR moves the applicant to an
    // accepted stage, using the email already stored in the database. Guarded
    // so it is sent at most once per applicant (prevents duplicate emails).
    if (in_array($newStatus, ['accepted', 'passed_screening'], true)
        && empty($fresh['acceptance_email_sent_at'])) {
        require_once __DIR__ . '/../../includes/mail.php';
        require_once __DIR__ . '/../../includes/exam.php';
        require_once __DIR__ . '/../../includes/security_log.php';

        $applicantName = trim($fresh['first_name'] . ' ' . $fresh['last_name']);

        // Ensure a secure, no-login exam access token exists (reuses the
        // existing opaque token + pending-request mechanism).
        $token = ensureApplicantExamToken((int) $id, (int) ($_SESSION['user_id'] ?? 0));
        $sent = false;
        if ($token !== null) {
            $examAccessLink = (defined('BASE_URL') ? BASE_URL : '/HR1')
                . '/modules/applicant/exam_access.php?token=' . urlencode($token);
            $sent = sendApplicationAcceptedEmail(
                $fresh['email'],
                $applicantName,
                $fresh['position_applied'],
                $examAccessLink
            );
        }

        if ($sent) {
            db()->prepare('UPDATE applicants SET acceptance_email_sent_at = NOW() WHERE id = ?')
                ->execute([$id]);
            securityLog(
                'app_acceptance_email_sent',
                'applicant_id=' . $id . ' status=' . $fresh['status'],
                $_SESSION['user_id']
            );
        } else {
            // Email failed — application status remains Accepted/Passed Screening.
            securityLog(
                'app_acceptance_email_failed',
                'applicant_id=' . $id . ' status=' . $fresh['status'],
                $_SESSION['user_id']
            );
        }
    }

    flash('success', 'Applicant updated successfully.');
    redirect(BASE_URL . '/modules/applicants/view.php?id=' . $id);
}

$stmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
$stmt->execute([$id]);
$applicant = $stmt->fetch();

if (!$applicant) {
    flash('danger', 'Applicant not found.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}

renderEditForm:
$pageTitle = 'Edit Applicant';
$currentModule = 'applicants';
$departments = getDepartments();
$jobPostings = db()->query("SELECT id, job_code, title FROM job_postings WHERE status IN ('open','draft') ORDER BY title")->fetchAll();
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <h1 class="page-title">Edit Applicant</h1>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline">← Back</a>
</div>

<?php if (!empty($errors)): ?>
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
            <label>Applicant No.</label>
            <input type="text" value="<?= e($applicant['applicant_no']) ?>" disabled>
        </div>
        <div class="form-group">
            <label for="first_name">First Name *</label>
            <input type="text" id="first_name" name="first_name" value="<?= e($applicant['first_name']) ?>" required>
        </div>
        <div class="form-group">
            <label for="last_name">Last Name *</label>
            <input type="text" id="last_name" name="last_name" value="<?= e($applicant['last_name']) ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" value="<?= e($applicant['email']) ?>" required>
        </div>
        <div class="form-group">
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" value="<?= e($applicant['phone']) ?>">
        </div>
        <div class="form-group">
            <label for="position_applied">Position Applied *</label>
            <input type="text" id="position_applied" name="position_applied" value="<?= e($applicant['position_applied']) ?>" required>
        </div>
        <div class="form-group">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id">
                <option value="">— Select —</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?= (int) $dept['id'] ?>" <?= $applicant['department_id'] == $dept['id'] ? 'selected' : '' ?>>
                    <?= e($dept['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="job_posting_id">Linked Job Posting</label>
            <select id="job_posting_id" name="job_posting_id">
                <option value="">— None —</option>
                <?php foreach ($jobPostings as $jp): ?>
                <option value="<?= (int) $jp['id'] ?>" <?= ($applicant['job_posting_id'] ?? '') == $jp['id'] ? 'selected' : '' ?>>
                    <?= e($jp['job_code'] . ' — ' . $jp['title']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="applied_date">Applied Date *</label>
            <input type="date" id="applied_date" name="applied_date" value="<?= e($applicant['applied_date']) ?>" required>
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['new','screening','shortlisted','accepted','passed_screening','interview','offered','hired','rejected'] as $s): ?>
                <option value="<?= $s ?>" <?= $applicant['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group full-width">
            <label for="address">Address</label>
            <textarea id="address" name="address" rows="2"><?= e($applicant['address']) ?></textarea>
        </div>
        <div class="form-group full-width">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3"><?= e($applicant['notes']) ?></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Update Applicant</button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
