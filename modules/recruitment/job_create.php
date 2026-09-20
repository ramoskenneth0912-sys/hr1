<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$validationErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $title = trim((string) ($_POST['title'] ?? ''));
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $aboutRole = trim((string) ($_POST['about_role'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $employmentType = trim((string) ($_POST['job_employment_type'] ?? ''));
    $workLocation = trim((string) ($_POST['work_location'] ?? ''));
    $salaryCompensation = trim((string) ($_POST['salary_compensation'] ?? ''));
    $vacancies = (int) ($_POST['vacancies'] ?? 1);
    $closingDate = trim((string) ($_POST['closing_date'] ?? ''));
    $status = $_POST['status'] ?? 'open';
    $qualifications = trim((string) ($_POST['qualifications'] ?? ''));
    $requiredSkills = trim((string) ($_POST['required_skills'] ?? ''));
    $educationReq = trim((string) ($_POST['education_requirement'] ?? ''));
    $experienceReq = trim((string) ($_POST['experience_requirement'] ?? ''));

    $employmentTypes = ['regular', 'contractual', 'probationary', 'part_time', 'internship'];
    $statuses = ['open', 'closed'];

    $validationErrors = [];

    if ($title === '') {
        $validationErrors[] = 'Job title is required.';
    }
    if ($departmentId <= 0) {
        $validationErrors[] = 'Department is required.';
    }
    if ($description === '') {
        $validationErrors[] = 'Job description is required.';
    }
    if ($qualifications === '') {
        $validationErrors[] = 'Qualifications are required.';
    }
    if ($employmentType === '' || !in_array($employmentType, $employmentTypes, true)) {
        $validationErrors[] = 'Employment type is required.';
    }
    if ($workLocation === '') {
        $validationErrors[] = 'Work location is required.';
    }
    if ($vacancies < 1) {
        $validationErrors[] = 'Vacancies must be at least 1.';
    }
    if ($closingDate === '') {
        $validationErrors[] = 'Closing date is required.';
    }
    if (!in_array($status, $statuses, true)) {
        $validationErrors[] = 'Invalid status value.';
    }
    if ($aboutRole === '') {
        $validationErrors[] = 'About the Role is required.';
    }
    if ($requiredSkills === '') {
        $validationErrors[] = 'Required Skills is required.';
    }
    if ($educationReq === '') {
        $validationErrors[] = 'Education Requirement is required.';
    }
    if ($experienceReq === '') {
        $validationErrors[] = 'Experience Requirement is required.';
    }
    if (in_array('', [$aboutRole, $requiredSkills, $educationReq, $experienceReq], true)) {
        array_unshift($validationErrors, 'Please complete all required job posting fields.');
    }

    // Closing must be a scheduled/future date.
    $validationErrors = array_merge($validationErrors, validateDateRange(null, $closingDate, true));

    if (!$validationErrors) {
        $jobCode = generateCode('JOB', 'job_postings', 'job_code');
        $stmt = db()->prepare(
            'INSERT INTO job_postings (job_code, title, department_id, about_role, description,
             vacancies, status, posted_date, closing_date, qualifications, required_skills,
             education_requirement, experience_requirement, work_location, job_employment_type,
             salary_compensation)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $jobCode,
            $title,
            $departmentId,
            $aboutRole ?: null,
            $description,
            $vacancies,
            $status,
            date('Y-m-d'),
            $closingDate ?: null,
            $qualifications,
            $requiredSkills,
            $educationReq,
            $experienceReq,
            $workLocation,
            $employmentType,
            $salaryCompensation ?: null,
        ]);
        flash('success', 'Job posting ' . $jobCode . ' created.');
        redirect(BASE_URL . '/modules/recruitment/index.php');
    }
    // On validation failure, fall through and re-render with submitted values.
}

$pageTitle = 'New Job Posting';
$currentModule = 'recruitment';
$departments = getDepartments();
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <h1 class="page-title">New Job Posting</h1>
    <div class="btn-group">
        <a href="recruitment_requests.php" class="btn btn-outline">Recruitment Requests</a>
        <a href="index.php" class="btn btn-outline">← Back</a>
    </div>
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
            <label for="title">Job Title *</label>
            <input type="text" id="title" name="title" required value="<?= e($_POST['title'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="department_id">Department *</label>
            <select id="department_id" name="department_id" required>
                <option value="">— Select —</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?= (int) $dept['id'] ?>" <?= (int) ($_POST['department_id'] ?? 0) === (int) $dept['id'] ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="vacancies">Vacancies</label>
            <input type="number" id="vacancies" name="vacancies" value="<?= e($_POST['vacancies'] ?? 1) ?>" min="1">
        </div>
        <div class="form-group">
            <label for="job_employment_type">Employment Type *</label>
            <select id="job_employment_type" name="job_employment_type" required>
                <?php foreach (['regular','contractual','probationary','part_time','internship'] as $t): ?>
                <option value="<?= $t ?>" <?= ($_POST['job_employment_type'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="work_location">Work Location *</label>
            <input type="text" id="work_location" name="work_location" required value="<?= e($_POST['work_location'] ?? '') ?>" placeholder="e.g. Makati City, Metro Manila">
        </div>
        <div class="form-group">
            <label for="salary_compensation">Salary / Compensation</label>
            <input type="text" id="salary_compensation" name="salary_compensation" maxlength="200"
                   value="<?= e($_POST['salary_compensation'] ?? '') ?>"
                   placeholder="e.g. ₱20,000 – ₱25,000 per month">
            <small style="font-size:.75rem;color:var(--muted);">Optional — enter the actual compensation for this position.</small>
        </div>
        <div class="form-group">
            <label for="closing_date">Application Closing Date *</label>
            <input type="date" id="closing_date" name="closing_date"
                   value="<?= e($_POST['closing_date'] ?? '') ?>" min="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['open','closed'] as $s): ?>
                <option value="<?= $s ?>" <?= ($_POST['status'] ?? 'open') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group full-width">
            <label for="about_role">About the Role *</label>
            <textarea id="about_role" name="about_role" rows="4" required placeholder="Briefly describe the purpose of this position, its main responsibilities, and what the successful candidate will contribute to the organization."><?= e($_POST['about_role'] ?? '') ?></textarea>
            <small style="font-size:.75rem;color:var(--muted);">A concise overview of why this position exists and what the employee will be responsible for.</small>
        </div>
        <div class="form-group full-width">
            <label for="description">Job Description *</label>
            <textarea id="description" name="description" rows="4" required><?= e($_POST['description'] ?? '') ?></textarea>
        </div>
        <div class="form-group full-width">
            <label for="qualifications">Qualifications *</label>
            <textarea id="qualifications" name="qualifications" rows="3" required><?= e($_POST['qualifications'] ?? '') ?></textarea>
        </div>
        <div class="form-group full-width">
            <label for="required_skills">Required Skills *</label>
            <textarea id="required_skills" name="required_skills" rows="3" required placeholder="e.g. SEO, Social Media Marketing, Content Creation"><?= e($_POST['required_skills'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="education_requirement">Education Requirement *</label>
            <input type="text" id="education_requirement" name="education_requirement" required value="<?= e($_POST['education_requirement'] ?? '') ?>" placeholder="e.g. Bachelor's Degree in Marketing">
        </div>
        <div class="form-group">
            <label for="experience_requirement">Experience Requirement *</label>
            <input type="text" id="experience_requirement" name="experience_requirement" required value="<?= e($_POST['experience_requirement'] ?? '') ?>" placeholder="e.g. 2+ years in marketing role">
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Job Posting</button>
        <button type="submit" formaction="job_preview.php" class="btn btn-outline">Preview &nearr;</button>
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
