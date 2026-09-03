<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$validationErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $title = trim((string) ($_POST['title'] ?? ''));
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $description = trim((string) ($_POST['description'] ?? ''));
    $employmentType = trim((string) ($_POST['job_employment_type'] ?? ''));
    $workLocation = trim((string) ($_POST['work_location'] ?? ''));
    $vacancies = (int) ($_POST['vacancies'] ?? 1);
    $postedDate = trim((string) ($_POST['posted_date'] ?? ''));
    $closingDate = trim((string) ($_POST['closing_date'] ?? ''));
    $status = $_POST['status'] ?? 'draft';
    $qualifications = trim((string) ($_POST['qualifications'] ?? ''));
    $requiredSkills = trim((string) ($_POST['required_skills'] ?? ''));
    $educationReq = trim((string) ($_POST['education_requirement'] ?? ''));
    $experienceReq = trim((string) ($_POST['experience_requirement'] ?? ''));

    $employmentTypes = ['regular', 'contractual', 'probationary', 'part_time', 'internship'];
    $statuses = ['draft', 'open', 'closed', 'filled'];

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
    if ($postedDate === '') {
        $validationErrors[] = 'Posted date is required.';
    }
    if ($closingDate === '') {
        $validationErrors[] = 'Closing date is required.';
    }
    if (!in_array($status, $statuses, true)) {
        $validationErrors[] = 'Invalid status value.';
    }

    // Scheduled/future posting dates + Closing must not precede Posted.
    $validationErrors = array_merge($validationErrors, validateDateRange($postedDate, $closingDate, true));

    if (!$validationErrors) {
        $jobCode = generateCode('JOB', 'job_postings', 'job_code');
        $stmt = db()->prepare(
            'INSERT INTO job_postings (job_code, title, department_id, description,
             vacancies, status, posted_date, closing_date, qualifications, required_skills,
             education_requirement, experience_requirement, work_location, job_employment_type)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $jobCode,
            $title,
            $departmentId,
            $description,
            $vacancies,
            $status,
            $postedDate ?: null,
            $closingDate ?: null,
            $qualifications,
            $requiredSkills,
            $educationReq,
            $experienceReq,
            $workLocation,
            $employmentType,
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
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['draft','open','closed','filled'] as $s): ?>
                <option value="<?= $s ?>" <?= ($_POST['status'] ?? 'draft') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="posted_date">Posted/Start Date *</label>
            <input type="date" id="posted_date" name="posted_date"
                   value="<?= e($_POST['posted_date'] ?? date('Y-m-d')) ?>" min="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
            <label for="closing_date">Application Closing Date *</label>
            <input type="date" id="closing_date" name="closing_date"
                   value="<?= e($_POST['closing_date'] ?? '') ?>" min="<?= date('Y-m-d') ?>" required>
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
            <label for="required_skills">Required Skills</label>
            <textarea id="required_skills" name="required_skills" rows="3" placeholder="e.g. SEO, Social Media Marketing, Content Creation"><?= e($_POST['required_skills'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="education_requirement">Education Requirement</label>
            <input type="text" id="education_requirement" name="education_requirement" value="<?= e($_POST['education_requirement'] ?? '') ?>" placeholder="e.g. Bachelor's Degree in Marketing">
        </div>
        <div class="form-group">
            <label for="experience_requirement">Experience Requirement</label>
            <input type="text" id="experience_requirement" name="experience_requirement" value="<?= e($_POST['experience_requirement'] ?? '') ?>" placeholder="e.g. 2+ years in marketing role">
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Job Posting</button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
