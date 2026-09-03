<?php
require_once __DIR__ . '/../../includes/auth.php';
requireApplicant();

require_once __DIR__ . '/../../includes/exam.php';

$currentUser = getCurrentUser();
$applicantEmail = $currentUser['email'] ?? '';

// Resolve ONLY the logged-in applicant's own applicant rows (IDOR guard).
$mine = db()->prepare(
    'SELECT id, applicant_no, position_applied, status FROM applicants
     WHERE user_id = ? OR email = ?'
);
$mine->execute([$_SESSION['user_id'], $applicantEmail]);
$myApplicants = $mine->fetchAll();

$myApplicantIds = array_map(static fn (array $a): int => (int) $a['id'], $myApplicants);

$finalEligibleAny = false;
foreach ($myApplicants as $mineRow) {
    if (applicantFinalInterviewEligible($mineRow)) {
        $finalEligibleAny = true;
        break;
    }
}

$assignments = [];
if (!empty($myApplicantIds)) {
    $placeholders = implode(',', array_fill(0, count($myApplicantIds), '?'));
    $stmt = db()->prepare(
        "SELECT ea.id AS assignment_id, ea.status AS assignment_status, ea.invited_at,
                ea.completed_at, ea.access_token,
                a.applicant_no, a.position_applied, a.status AS applicant_status,
                e.title AS exam_title, e.source_provider,
                j.title AS job_title, j.job_code,
                er.passed AS result_passed, er.percentage AS result_percentage,
                er.result_status, er.scored_at
         FROM exam_assignments ea
         JOIN applicants a ON a.id = ea.applicant_id
         LEFT JOIN exams e ON e.id = ea.exam_id
         LEFT JOIN job_postings j ON j.id = ea.job_posting_id
         LEFT JOIN exam_attempts at ON at.assignment_id = ea.id
         LEFT JOIN exam_results er ON er.attempt_id = at.id
         WHERE ea.applicant_id IN ($placeholders)
         ORDER BY ea.invited_at DESC"
    );
    $stmt->execute($myApplicantIds);
    $assignments = $stmt->fetchAll();
}

$pageTitle = 'My Examinations';
$currentModule = 'applicant-dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">My Examinations</h1>
        <p class="page-subtitle">Examinations assigned to your applications</p>
    </div>
    <a href="dashboard.php" class="btn btn-outline">← My Applications</a>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Assigned Examinations</h2>
    <?php if (empty($assignments)): ?>
    <div class="empty" style="padding:2rem;text-align:center;color:var(--muted);">
        <p>You have no examinations assigned at this time.</p>
        <p><small>Examinations are assigned by HR after your application passes initial screening.</small></p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Examination</th>
                <th>Position</th>
                <th>Assignment Status</th>
                <th>Result</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($assignments as $a): ?>
            <tr>
                <td>
                    <?php if (!empty($a['exam_title'])): ?>
                    <strong><?= e($a['exam_title']) ?></strong>
                    <br><small><?= e($a['source_provider']) ?></small>
                    <?php else: ?>
                    <strong>Examination requested</strong>
                    <br><small>Awaiting external provider</small>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e($a['job_title'] ?? $a['position_applied']) ?>
                    <br><small><?= e($a['applicant_no']) ?></small>
                </td>
                <td><?= statusBadge($a['assignment_status']) ?></td>
                <td>
                    <?php if ($a['result_status'] !== null): ?>
                    <span class="badge <?= $a['result_passed'] ? 'badge-success' : 'badge-danger' ?>">
                        <?= $a['result_passed'] ? 'Passed' : 'Failed' ?>
                    </span>
                    <span><?= e((string) $a['result_percentage']) ?>%</span>
                    <?php else: ?>
                    <span class="text-muted">Pending</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($finalEligibleAny): ?>
    <div class="alert alert-success" style="margin-top:1rem;">
        <strong>Congratulations!</strong> You have passed your examination. While HR1 does not automatically proceed, you are now
        eligible for the <strong>final interview</strong>. HR will contact you to schedule it.
    </div>
    <?php endif; ?>
    <p class="text-muted" style="font-size:.8rem;margin-top:1rem;">
        The examination itself is conducted by an external provider. HR1 shows you the assigned examination and the returned result only.
    </p>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
