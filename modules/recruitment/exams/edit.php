<?php
require_once __DIR__ . '/../../../includes/auth.php';
requireHRorManager();

require_once __DIR__ . '/../../../includes/exam.php';

$id = (int) ($_GET['id'] ?? 0);
$exam = findExam($id);
if (!$exam) {
    flash('danger', 'Examination not found.');
    redirect(BASE_URL . '/modules/recruitment/exams/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $jobId = (int) ($_POST['job_posting_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $sourceProvider = trim((string) ($_POST['source_provider'] ?? 'HR3'));
    $externalRef = trim((string) ($_POST['external_ref'] ?? ''));
    $passingScore = (float) ($_POST['passing_score'] ?? 60);
    $timeLimit = max(1, (int) ($_POST['time_limit_minutes'] ?? 30));
    $maxAttempts = max(1, (int) ($_POST['max_attempts'] ?? 1));
    $status = trim((string) ($_POST['status'] ?? 'draft'));
    $description = trim((string) ($_POST['description'] ?? ''));
    $instructions = trim((string) ($_POST['instructions'] ?? ''));
    $examUrl = trim((string) ($_POST['exam_url'] ?? ''));

    $renderErrors = [];
    if ($title === '' || mb_strlen($title) > 200) {
        $renderErrors['title'] = 'A title (max 200 characters) is required.';
    }
    if ($jobId <= 0) {
        $renderErrors['job_posting_id'] = 'The associated Job/Position is required.';
    }
    if ($sourceProvider === '' || mb_strlen($sourceProvider) > 80) {
        $renderErrors['source_provider'] = 'A source provider (max 80 characters) is required.';
    }
    if ($externalRef !== '' && mb_strlen($externalRef) > 120) {
        $renderErrors['external_ref'] = 'The external reference may not exceed 120 characters.';
    }
    if ($passingScore < 0 || $passingScore > 100) {
        $renderErrors['passing_score'] = 'Passing score must be between 0 and 100.';
    }
    if (!in_array($status, ['draft', 'active', 'retired'], true)) {
        $renderErrors['status'] = 'Invalid status.';
    }
    if (mb_strlen($description) > 2000) {
        $renderErrors['description'] = 'Description may not exceed 2000 characters.';
    }
    if (mb_strlen($instructions) > 4000) {
        $renderErrors['instructions'] = 'Instructions may not exceed 4000 characters.';
    }
    if ($examUrl !== '') {
        $urlOk = filter_var($examUrl, FILTER_VALIDATE_URL) !== false;
        $parsed = parse_url($examUrl);
        $isHttps = $urlOk && isset($parsed['scheme']) && strtolower((string) $parsed['scheme']) === 'https';
        if (!$urlOk || !$isHttps || mb_strlen($examUrl) > 500) {
            $renderErrors['exam_url'] = 'The examination URL must be a valid HTTPS URL (max 500 characters).';
        }
    }

    if (empty($renderErrors)) {
        $stmt = db()->prepare(
            'UPDATE exams SET title=?, job_posting_id=?, description=?, instructions=?, exam_url=?,
                passing_score=?, time_limit_minutes=?, max_attempts=?, source_provider=?, external_ref=?, status=?
             WHERE id=?'
        );
        $stmt->execute([
            $title,
            $jobId,
            $description !== '' ? $description : null,
            $instructions !== '' ? $instructions : null,
            $examUrl !== '' ? $examUrl : null,
            $passingScore,
            $timeLimit,
            $maxAttempts,
            $sourceProvider,
            $externalRef !== '' ? $externalRef : null,
            $status,
            $id,
        ]);

        require_once __DIR__ . '/../../../includes/security_log.php';
        securityLog('exam_updated', "exam_id={$id} title=" . mb_substr($title, 0, 60), $_SESSION['user_id'] ?? null);

        flash('success', 'Examination reference updated.');
        redirect(BASE_URL . '/modules/recruitment/exams/view.php?id=' . $id);
    }

    // Repopulate on validation failure
    $exam = array_merge($exam, [
        'job_posting_id' => $jobId,
        'title' => $title,
        'source_provider' => $sourceProvider,
        'external_ref' => $externalRef,
        'passing_score' => $passingScore,
        'time_limit_minutes' => $timeLimit,
        'max_attempts' => $maxAttempts,
        'status' => $status,
        'description' => $description,
        'instructions' => $instructions,
        'exam_url' => $examUrl,
    ]);
}

$jobs = db()->query(
    'SELECT j.id, j.job_code, j.title, d.name AS department_name
     FROM job_postings j LEFT JOIN departments d ON d.id = j.department_id
     ORDER BY j.title ASC'
)->fetchAll();

$pageTitle = 'Edit Examination';
$currentModule = 'exams';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Edit Examination</h1>
        <p class="page-subtitle"><?= e($exam['title']) ?></p>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline">← Back</a>
</div>

<?php if (!empty($renderErrors)): ?>
<div class="alert alert-danger">
    <?= e(implode(' ', array_values($renderErrors))) ?>
</div>
<?php endif; ?>

<form method="post" class="form-panel fade-in-up" style="animation-delay:.1s">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="form-group full-width">
            <label for="title">Examination Title *</label>
            <input type="text" id="title" name="title" value="<?= e($exam['title']) ?>" required>
        </div>
        <div class="form-group full-width">
            <label for="job_posting_id">Associated Job / Position *</label>
            <select id="job_posting_id" name="job_posting_id" required>
                <option value="">— Select Job / Position —</option>
                <?php foreach ($jobs as $j): ?>
                <option value="<?= (int) $j['id'] ?>" <?= (int) $exam['job_posting_id'] === (int) $j['id'] ? 'selected' : '' ?>>
                    <?= e($j['job_code'] . ' — ' . $j['title'] . ($j['department_name'] ? ' (' . $j['department_name'] . ')' : '')) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="source_provider">Source Provider *</label>
            <input type="text" id="source_provider" name="source_provider" value="<?= e($exam['source_provider']) ?>" required>
        </div>
        <div class="form-group">
            <label for="external_ref">External Provider Reference</label>
            <input type="text" id="external_ref" name="external_ref" value="<?= e($exam['external_ref'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="passing_score">Passing Score (%)</label>
            <input type="number" id="passing_score" name="passing_score" min="0" max="100" step="0.01" value="<?= e($exam['passing_score']) ?>">
        </div>
        <div class="form-group">
            <label for="time_limit_minutes">Time Limit (minutes)</label>
            <input type="number" id="time_limit_minutes" name="time_limit_minutes" min="1" value="<?= (int) $exam['time_limit_minutes'] ?>">
        </div>
        <div class="form-group">
            <label for="max_attempts">Max Attempts</label>
            <input type="number" id="max_attempts" name="max_attempts" min="1" value="<?= (int) $exam['max_attempts'] ?>">
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['draft', 'active', 'retired'] as $s): ?>
                <option value="<?= $s ?>" <?= $exam['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <small style="color:var(--muted)">Only active examinations can be assigned.</small>
        </div>
        <div class="form-group full-width">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="2"><?= e($exam['description'] ?? '') ?></textarea>
        </div>
        <div class="form-group full-width">
            <label for="instructions">Instructions</label>
            <textarea id="instructions" name="instructions" rows="3"><?= e($exam['instructions'] ?? '') ?></textarea>
            <small style="color:var(--muted)">Shown to the applicant on the examination access page.</small>
        </div>
        <div class="form-group full-width">
            <label for="exam_url">Examination (Provider) URL</label>
            <input type="url" id="exam_url" name="exam_url" value="<?= e($exam['exam_url'] ?? '') ?>" placeholder="https://provider.example.com/exam/..." >
            <small style="color:var(--muted)">HTTPS URL of the external examination-taking source (e.g. HR3) launched by the applicant’s "Start Examination" button. Not sent in any email.</small>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
