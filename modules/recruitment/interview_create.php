<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

require_once __DIR__ . '/../../includes/exam.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $applicantId = (int) ($_POST['applicant_id'] ?? 0);
    if ($applicantId <= 0) {
        flash('danger', 'Please select an applicant.');
        redirect(BASE_URL . '/modules/recruitment/interview_create.php');
    }

    // Interview is a scheduled/future event: reject past or present datetimes.
    $interviewDatetime = trim((string) ($_POST['interview_date'] ?? ''));
    if ($interviewDatetime === '') {
        flash('danger', 'Interview date & time is required.');
        redirect(BASE_URL . '/modules/recruitment/interview_create.php');
    }
    $dtParsed = DateTime::createFromFormat('Y-m-d\TH:i', $interviewDatetime);
    if ($dtParsed === false || $dtParsed->format('Y-m-d\TH:i') !== $interviewDatetime) {
        flash('danger', 'Interview date & time is invalid.');
        redirect(BASE_URL . '/modules/recruitment/interview_create.php');
    }
    if ($dtParsed->format('Y-m-d H:i') <= date('Y-m-d H:i')) {
        flash('danger', 'Interview date must be a future date.');
        redirect(BASE_URL . '/modules/recruitment/interview_create.php');
    }

    $stmt = db()->prepare(
        'INSERT INTO interviews (applicant_id, job_posting_id, interview_date, interviewer, location, result, remarks)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        (int) $_POST['applicant_id'],
        $_POST['job_posting_id'] ?: null,
        $_POST['interview_date'],
        trim($_POST['interviewer'] ?? ''),
        trim($_POST['location'] ?? ''),
        $_POST['result'] ?? 'pending',
        trim($_POST['remarks'] ?? ''),
    ]);

    // Applicant status advance. A FINAL interview can be scheduled for an
    // applicant who PASSED their examination; their status only moves to
    // "interview" because the HR/Admin user actually scheduled it here (the
    // exam result itself never auto-selects or auto-hires anyone).
    $aStmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
    $aStmt->execute([(int) $_POST['applicant_id']]);
    $applicantRow = $aStmt->fetch() ?: [];

    $allowedFrom = ['new', 'screening'];
    if (in_array($applicantRow['status'] ?? '', ['accepted', 'passed_screening'], true)
        && applicantFinalInterviewEligible($applicantRow)) {
        $allowedFrom[] = 'accepted';
        $allowedFrom[] = 'passed_screening';
    }
    $inList = implode(',', array_map(static fn ($s) => db()->quote($s), $allowedFrom));

    db()->prepare("UPDATE applicants SET status = 'interview' WHERE id = ? AND status IN ($inList)")
        ->execute([(int) $_POST['applicant_id']]);

    // Notify the applicant's account about the scheduled interview.
    if ($applicantRow) {
        $when = date('F j, Y \a\t g:i A', strtotime($_POST['interview_date']));
        $isFinal = in_array($applicantRow['status'] ?? '', ['accepted', 'passed_screening'], true)
            && applicantFinalInterviewEligible($applicantRow);
        notifyUser(
            applicantUserId($applicantRow),
            'Interview scheduled',
            'Your ' . ($isFinal ? 'final ' : '') . 'interview for ' . $applicantRow['position_applied'] . ' is scheduled for ' . $when . '.',
            BASE_URL . '/modules/applicant/dashboard.php'
        );
    }

    flash('success', 'Interview scheduled successfully.');
    redirect(BASE_URL . '/modules/recruitment/index.php');
}

$pageTitle = 'Schedule Interview';
$currentModule = 'recruitment';
$applicants = getApplicants();
$jobs = db()->query("SELECT id, job_code, title FROM job_postings WHERE status IN ('open','draft') ORDER BY title")->fetchAll();

// Optional pre-selected applicant (convenient entry point from Applicant
// Management). Validated against the applicant list below.
$preselectId = (int) ($_GET['applicant_id'] ?? 0);
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <h1 class="page-title">Schedule Interview</h1>
    <a href="index.php" class="btn btn-outline">← Back</a>
</div>

<form method="post" class="form-panel fade-in-up" style="animation-delay:.1s">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="form-group">
            <label for="applicant_id">Applicant *</label>
            <select id="applicant_id" name="applicant_id" required>
                <option value="">— Select —</option>
                <?php foreach ($applicants as $a): ?>
                <?php $finalEligible = in_array($a['status'] ?? '', ['accepted', 'passed_screening'], true) && applicantFinalInterviewEligible($a); ?>
                <option value="<?= (int) $a['id'] ?>" <?= $preselectId === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['applicant_no'] . ' — ' . $a['first_name'] . ' ' . $a['last_name'] . ($finalEligible ? '  [Final Interview — exam passed]' : '')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="job_posting_id">Job Posting</label>
            <select id="job_posting_id" name="job_posting_id">
                <option value="">— Select —</option>
                <?php foreach ($jobs as $j): ?>
                <option value="<?= (int) $j['id'] ?>"><?= e($j['job_code'] . ' — ' . $j['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="interview_date">Interview Date & Time *</label>
            <input type="datetime-local" id="interview_date" name="interview_date" required min="<?= date('Y-m-d\TH:i') ?>">
        </div>
        <div class="form-group">
            <label for="interviewer">Interviewer</label>
            <input type="text" id="interviewer" name="interviewer">
        </div>
        <div class="form-group">
            <label for="location">Location</label>
            <input type="text" id="location" name="location">
        </div>
        <div class="form-group">
            <label for="result">Result</label>
            <select id="result" name="result">
                <?php foreach (['pending','passed','failed','rescheduled'] as $s): ?>
                <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group full-width">
            <label for="remarks">Remarks</label>
            <textarea id="remarks" name="remarks" rows="3"></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Schedule Interview</button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
