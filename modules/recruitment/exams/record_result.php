<?php
require_once __DIR__ . '/../../../includes/auth.php';
requireHRorManager();

require_once __DIR__ . '/../../../includes/exam.php';

$assignmentId = (int) ($_GET['assignment_id'] ?? ($_POST['assignment_id'] ?? 0));

$asnStmt = db()->prepare(
    'SELECT ea.*, a.first_name, a.last_name, a.applicant_no, a.email, a.job_posting_id, a.status AS applicant_status,
            e.title AS exam_title, e.job_posting_id AS exam_job_id, e.source_provider,
            j.title AS job_title, j.job_code
     FROM exam_assignments ea
     JOIN applicants a ON a.id = ea.applicant_id
     JOIN exams e ON e.id = ea.exam_id
     LEFT JOIN job_postings j ON j.id = ea.job_posting_id
     WHERE ea.id = ? LIMIT 1'
);
$asnStmt->execute([$assignmentId]);
$asn = $asnStmt->fetch();

if (!$asn) {
    flash('danger', 'Assignment not found.');
    redirect(BASE_URL . '/modules/recruitment/exams/index.php');
}

// Exam's passing score (outcome is derived server-side against this).
$psStmt = db()->prepare('SELECT passing_score FROM exams WHERE id = ? LIMIT 1');
$psStmt->execute([(int) $asn['exam_id']]);
$examPassingScore = (float) ($psStmt->fetch()['passing_score'] ?? 60.0);

// Load latest recorded result (if any) via its attempt.
$latestResult = null;
$latestAttempt = null;
$resStmt = db()->prepare(
    'SELECT er.*, at.attempt_number, at.submitted_at
     FROM exam_attempts at
     LEFT JOIN exam_results er ON er.attempt_id = at.id
     WHERE at.assignment_id = ?
     ORDER BY at.attempt_number DESC LIMIT 1'
);
$resStmt->execute([$assignmentId]);
$latest = $resStmt->fetch();
if ($latest && $latest['id'] !== null) {
    $latestResult = $latest;
    $latestAttempt = $latest;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if (in_array($asn['status'], ['completed', 'expired', 'voided'], true)) {
        flash('warning', 'This assignment is already finalised and can no longer be modified.');
        redirect(BASE_URL . '/modules/recruitment/exams/record_result.php?assignment_id=' . $assignmentId);
    }

    $earned = (float) ($_POST['earned_points'] ?? 0);
    $total = (float) ($_POST['total_points'] ?? 0);
    $externalResultId = trim((string) ($_POST['external_result_id'] ?? ''));

    // Delegate to the single hardened, server-validated ingestion path.
    // Pass/fail is DERIVED server-side from the exam's passing_score — the
    // person recording the result cannot override the outcome.
    $out = recordExamResult(
        $asn,
        $earned,
        $total,
        $externalResultId !== '' ? $externalResultId : null,
        null,
        null,
        'manual'
    );

    if (!$out['ok']) {
        flash('danger', $out['message']);
        redirect(BASE_URL . '/modules/recruitment/exams/record_result.php?assignment_id=' . $assignmentId);
    }

    flash('success', 'Examination result recorded (' . ($out['passed'] ? 'Passed' : 'Failed') . ').'
        . ($out['passed'] ? ' The applicant is now eligible for the final interview.' : ''));
    redirect(BASE_URL . '/modules/recruitment/exams/view.php?id=' . (int) $asn['exam_id']);
}

$pageTitle = 'Examination Result — ' . $asn['first_name'] . ' ' . $asn['last_name'];
$currentModule = 'exams';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Examination Result</h1>
        <p class="page-subtitle"><?= e($asn['applicant_no'] . ' — ' . $asn['first_name'] . ' ' . $asn['last_name']) ?></p>
    </div>
    <a href="view.php?id=<?= (int) $asn['exam_id'] ?>" class="btn btn-outline">← Back to Examination</a>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Assignment &amp; Applicant</h2>
    <div class="detail-grid">
        <div class="detail-item"><label>Examination</label><span><?= e($asn['exam_title']) ?> (<?= e($asn['source_provider']) ?>)</span></div>
        <div class="detail-item"><label>Job / Position</label><span><?= e($asn['job_code'] ?? '—') ?> — <?= e($asn['job_title'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Applicant Status</label><span><?= statusBadge($asn['applicant_status']) ?></span></div>
        <div class="detail-item"><label>Assignment Status</label><span><?= statusBadge($asn['status']) ?></span></div>
        <div class="detail-item"><label>Invited</label><span><?= formatDate($asn['invited_at']) ?></span></div>
        <div class="detail-item full-width"><label>Assignment Reference</label><span style="font-family:monospace;"><?= e($asn['access_token']) ?></span></div>
    </div>
</section>

<section class="panel fade-in-up" style="animation-delay:.15s">
    <h2>Returned Result</h2>

    <?php if ($latestResult): ?>
    <div class="detail-grid">
        <div class="detail-item"><label>Outcome</label><span>
            <span class="badge <?= $latestResult['passed'] ? 'badge-success' : 'badge-danger' ?>"><?= $latestResult['passed'] ? 'Passed' : 'Failed' ?></span>
            <span class="badge badge-secondary"><?= e(ucfirst((string) $latestResult['result_status'])) ?></span>
        </span></div>
        <div class="detail-item"><label>Percentage</label><span><?= e((string) $latestResult['percentage']) ?>%</span></div>
        <div class="detail-item"><label>Score</label><span><?= e((string) $latestResult['earned_points']) ?> / <?= e((string) $latestResult['total_points']) ?></span></div>
        <div class="detail-item"><label>Scored At</label><span><?= formatDate($latestResult['scored_at']) ?></span></div>
    </div>
    <div class="alert alert-info" style="margin-top:1rem;">
        This result has already been recorded. Assignments that are completed, expired, or voided are final and cannot be modified.
    </div>
    <?php else: ?>
    <p style="color:var(--muted)">No result has been returned yet. Record the outcome returned by the external HR3 provider below (HR1 stores only this outcome — never the exam content or answers).</p>
    <form method="post" action="record_result.php" class="form-panel" style="margin-top:1rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="assignment_id" value="<?= $assignmentId ?>">
        <div class="form-grid">
            <div class="form-group">
                <label for="earned_points">Earned Points *</label>
                <input type="number" id="earned_points" name="earned_points" min="0" step="0.01" required>
            </div>
            <div class="form-group">
                <label for="total_points">Total Points *</label>
                <input type="number" id="total_points" name="total_points" min="0.01" step="0.01" required>
            </div>
            <div class="form-group">
                <label for="external_result_id">External Result Ref (optional)</label>
                <input type="text" id="external_result_id" name="external_result_id" maxlength="120" placeholder="HR3 result id">
            </div>
        </div>
        <div class="alert alert-info" style="margin-top:1rem;">
            The <strong>Pass / Fail outcome is derived by HR1 server-side</strong> from the score against this examination's passing score
            (<?= e((string) $examPassingScore) ?>%). It cannot be overridden when recording the result.
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Record Result</button>
            <a href="view.php?id=<?= (int) $asn['exam_id'] ?>" class="btn btn-outline">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
