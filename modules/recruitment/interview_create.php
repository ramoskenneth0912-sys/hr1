<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
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

    db()->prepare("UPDATE applicants SET status = 'interview' WHERE id = ? AND status IN ('new','screening')")
        ->execute([(int) $_POST['applicant_id']]);

    // Notify the applicant's account about the scheduled interview.
    $aStmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
    $aStmt->execute([(int) $_POST['applicant_id']]);
    if ($applicantRow = $aStmt->fetch()) {
        $when = date('F j, Y \a\t g:i A', strtotime($_POST['interview_date']));
        notifyUser(
            applicantUserId($applicantRow),
            'Interview scheduled',
            'Your interview for ' . $applicantRow['position_applied'] . ' is scheduled for ' . $when . '.',
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
                <option value="<?= (int) $a['id'] ?>"><?= e($a['applicant_no'] . ' — ' . $a['first_name'] . ' ' . $a['last_name']) ?></option>
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
            <input type="datetime-local" id="interview_date" name="interview_date" required>
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
