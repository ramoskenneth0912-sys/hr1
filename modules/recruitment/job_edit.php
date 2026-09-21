<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid job posting ID.');
    redirect(BASE_URL . '/modules/recruitment/index.php');
}

/**
 * Server-side date validation (authoritative). Closing date must be a future
 * date (later than today). Today itself is rejected.
 *
 * @param string $closingDate submitted closing_date (Y-m-d, may be empty)
 * @return string[] Human readable validation error messages (empty = valid)
 */
function validateJobDates(string $closingDate): array
{
    $errors = [];
    $today = date('Y-m-d');
    $valid = static function (string $d): bool {
        if ($d === '') {
            return true;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    };

    if (!$valid($closingDate)) {
        $errors[] = 'Closing date is invalid.';
    } elseif ($closingDate !== '' && $closingDate <= $today) {
        $errors[] = 'Closing date must be a future date.';
    }

    return $errors;
}

$validationErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $validationErrors = validateJobDates(
        trim((string) ($_POST['closing_date'] ?? ''))
    );

    if (trim((string) ($_POST['title'] ?? '')) === '') {
        $validationErrors[] = 'Job title is required.';
    }

    $aboutRoleVal = trim((string) ($_POST['about_role'] ?? ''));
    $requiredSkillsVal = trim((string) ($_POST['required_skills'] ?? ''));
    $educationReqVal = trim((string) ($_POST['education_requirement'] ?? ''));
    $experienceReqVal = trim((string) ($_POST['experience_requirement'] ?? ''));
    if ($aboutRoleVal === '') {
        $validationErrors[] = 'About the Role is required.';
    }
    if ($requiredSkillsVal === '') {
        $validationErrors[] = 'Required Skills is required.';
    }
    if ($educationReqVal === '') {
        $validationErrors[] = 'Education Requirement is required.';
    }
    if ($experienceReqVal === '') {
        $validationErrors[] = 'Experience Requirement is required.';
    }
if (in_array('', [$aboutRoleVal, $requiredSkillsVal, $educationReqVal, $experienceReqVal], true)) {
        array_unshift($validationErrors, 'Please complete all required job posting fields.');
    }

    if (!$validationErrors) {
        // Requirements is intentionally NOT updated here so the existing
        // database value is preserved.
$stmt = db()->prepare(
            'UPDATE job_postings SET title=?, department_id=?, about_role=?, description=?,
             vacancies=?, status=?, closing_date=?, qualifications=?,
             required_skills=?, education_requirement=?, experience_requirement=?,
             work_location=?, job_employment_type=?, salary_compensation=? WHERE id=?'
        );
        $stmt->execute([
            trim($_POST['title']),
            $_POST['department_id'] ?: null,
            trim((string) ($_POST['about_role'] ?? '')) ?: null,
            trim($_POST['description'] ?? ''),
            (int) $_POST['vacancies'],
            $_POST['status'],
            $_POST['closing_date'] ?: null,
            trim($_POST['qualifications'] ?? ''),
            trim($_POST['required_skills'] ?? ''),
            trim($_POST['education_requirement'] ?? ''),
            trim($_POST['experience_requirement'] ?? ''),
            trim($_POST['work_location'] ?? ''),
            $_POST['job_employment_type'] ?? 'regular',
            trim((string) ($_POST['salary_compensation'] ?? '')) ?: null,
            $id,
        ]);
        flash('success', 'Job posting updated.');
        redirect(BASE_URL . '/modules/recruitment/index.php');
    }
    // Otherwise fall through and re-render with the submitted values so the
    // HR/Admin can correct the invalid future date(s).
}

$stmt = db()->prepare('SELECT * FROM job_postings WHERE id = ?');
$stmt->execute([$id]);
$job = $stmt->fetch();
if (!$job) {
    flash('danger', 'Job posting not found.');
    redirect(BASE_URL . '/modules/recruitment/index.php');
}

// If validation failed, re-render using the submitted values (the authoritative
// DB row remains untouched until a valid save succeeds).
if ($validationErrors) {
    foreach ([
'title', 'department_id', 'about_role', 'description', 'vacancies', 'status',
        'closing_date', 'qualifications', 'required_skills',
        'education_requirement', 'experience_requirement', 'work_location',
        'job_employment_type', 'salary_compensation',
    ] as $k) {
        if (array_key_exists($k, $_POST)) {
            $job[$k] = $_POST[$k];
        }
    }
}

$pageTitle = 'Edit Job Posting';
$currentModule = 'recruitment';
$departments = getDepartments();
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <h1 class="page-title">Edit Job Posting</h1>
    <a href="index.php" class="btn btn-outline">← Back</a>
</div>

<?php if ($validationErrors): ?>
<div class="alert alert-danger" style="margin-bottom:1rem;">
    <ul style="margin:0;padding-left:1.25rem;">
        <?php foreach ($validationErrors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" class="form-panel fade-in-up" style="animation-delay:.1s">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="form-group">
            <label>Job Code</label>
            <input type="text" value="<?= e($job['job_code']) ?>" disabled>
        </div>
        <div class="form-group">
            <label for="title">Job Title *</label>
            <input type="text" id="title" name="title" value="<?= e($job['title']) ?>" required>
        </div>
        <div class="form-group">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id">
                <option value="">— Select —</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?= (int) $dept['id'] ?>" <?= $job['department_id'] == $dept['id'] ? 'selected' : '' ?>>
                    <?= e($dept['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="vacancies">Vacancies</label>
            <input type="number" id="vacancies" name="vacancies" value="<?= (int) $job['vacancies'] ?>" min="1">
        </div>
        <div class="form-group">
            <label for="job_employment_type">Employment Type</label>
            <select id="job_employment_type" name="job_employment_type">
                <?php foreach (['regular','contractual','probationary','part_time','internship'] as $t): ?>
                <option value="<?= $t ?>" <?= ($job['job_employment_type'] ?? 'regular') === $t ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="work_location">Work Location</label>
            <input type="text" id="work_location" name="work_location" value="<?= e($job['work_location'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['open','closed'] as $s): ?>
                <option value="<?= $s ?>" <?= $job['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
<div class="form-group">
            <label for="closing_date">Closing Date</label>
            <input type="date" id="closing_date" name="closing_date"
                   value="<?= e($job['closing_date']) ?>" min="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
            <label for="salary_compensation">Salary / Compensation</label>
            <input type="text" id="salary_compensation" name="salary_compensation" maxlength="200"
                   value="<?= e($job['salary_compensation'] ?? '') ?>"
                   placeholder="e.g. ₱20,000 – ₱25,000 per month">
        </div>
<div class="form-group full-width">
            <label for="about_role">About the Role *</label>
            <textarea id="about_role" name="about_role" rows="4" required placeholder="Briefly describe the purpose of this position, its main responsibilities, and what the successful candidate will contribute to the organization."><?= e($job['about_role'] ?? '') ?></textarea>
        </div>
        <div class="form-group full-width">
            <label for="description">Job Description</label>
            <textarea id="description" name="description" rows="4"><?= e($job['description']) ?></textarea>
        </div>
        <div class="form-group full-width">
            <label for="qualifications">Qualifications</label>
            <textarea id="qualifications" name="qualifications" rows="3"><?= e($job['qualifications'] ?? '') ?></textarea>
        </div>
<div class="form-group full-width">
            <label for="required_skills">Required Skills *</label>
            <textarea id="required_skills" name="required_skills" rows="3" required><?= e($job['required_skills'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="education_requirement">Education Requirement *</label>
            <input type="text" id="education_requirement" name="education_requirement" required value="<?= e($job['education_requirement'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="experience_requirement">Experience Requirement *</label>
            <input type="text" id="experience_requirement" name="experience_requirement" required value="<?= e($job['experience_requirement'] ?? '') ?>">
        </div>
    </div>
<div class="form-actions">
        <button type="submit" class="btn btn-primary">Update Job Posting</button>
        <a href="<?= e(BASE_URL) ?>/public/job_detail.php?id=<?= (int) $job['id'] ?>" target="_blank" rel="noopener" class="btn btn-outline">Preview Posting &nearr;</a>
    </div>
</form>
<script>
(function () {
    var requiredFields = [
        ['about_role', 'About the Role is required.'],
        ['required_skills', 'Required Skills is required.'],
        ['education_requirement', 'Education Requirement is required.'],
        ['experience_requirement', 'Experience Requirement is required.']
    ];
    requiredFields.forEach(function (pair) {
        var el = document.getElementById(pair[0]);
        if (!el) { return; }
        function refresh() {
            el.setCustomValidity(el.value.trim() ? '' : pair[1]);
        }
        el.addEventListener('input', refresh);
        el.addEventListener('change', refresh);
        refresh();
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
