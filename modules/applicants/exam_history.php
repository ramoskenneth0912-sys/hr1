<?php
/**
 * Centralized Exam History for HR/Admin (accessed from Applicant Management).
 *
 * The Applicant Management page is the main entry point for exam-related
 * actions. This separate Exam History page is for HR/Admin to REVIEW completed
 * examination records/results across applicants: Applicant No., Applicant
 * Name, Applied Position, Exam, Exam Date, Score, Percentage, Result, Final
 * Interview Eligibility, and per-record actions.
 *
 * This is a VIEWING page for exam records/results only. It reuses HR1's
 * existing exam/result functionality and business rules. Passing an exam
 * merely makes an applicant ELIGIBLE for a final interview — it does NOT
 * schedule an interview, select, hire, or onboard the applicant. Final
 * Interview continues to use the existing Interview module.
 *
 * HR/Admin only — enforced server-side (requireHRorManager), CSRF on any
 * state-changing form actions.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

require_once __DIR__ . '/../../includes/exam.php';

// Optional assignment-status filter derived from the ENUM in exam_assignments.
$allowedStatuses = ['assigned', 'in_progress', 'completed', 'expired', 'voided'];
$filter = trim((string) ($_GET['filter'] ?? ''));
if ($filter !== '' && !in_array($filter, $allowedStatuses, true)) {
    $filter = '';
}
$filter = in_array($filter, $allowedStatuses, true) ? $filter : '';

$sql = "SELECT ea.*,
               a.applicant_no, a.first_name, a.last_name, a.email, a.position_applied,
               a.status AS applicant_status, a.job_posting_id AS applicant_job_id,
               e.title AS exam_title, e.passing_score, e.job_posting_id AS exam_job_id,
               (SELECT er.earned_points FROM exam_results er
                  JOIN exam_attempts at ON at.id = er.attempt_id
                 WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1) AS result_earned,
               (SELECT er.total_points FROM exam_results er
                  JOIN exam_attempts at ON at.id = er.attempt_id
                 WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1) AS result_total,
               (SELECT er.percentage FROM exam_results er
                  JOIN exam_attempts at ON at.id = er.attempt_id
                 WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1) AS result_percentage,
               (SELECT er.passed FROM exam_results er
                  JOIN exam_attempts at ON at.id = er.attempt_id
                 WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1) AS result_passed,
               (SELECT er.result_status FROM exam_results er
                  JOIN exam_attempts at ON at.id = er.attempt_id
                 WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1) AS result_status,
               (SELECT er.scored_at FROM exam_results er
                  JOIN exam_attempts at ON at.id = er.attempt_id
                 WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1) AS result_date
        FROM exam_assignments ea
        JOIN applicants a ON a.id = ea.applicant_id
        LEFT JOIN exams e ON e.id = ea.exam_id";

$params = [];
if ($filter !== '') {
    $sql .= ' WHERE ea.status = ?';
    $params[] = $filter;
}
$sql .= " ORDER BY (
              SELECT er.scored_at FROM exam_results er
                JOIN exam_attempts at ON at.id = er.attempt_id
               WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1
          ) DESC, ea.created_at DESC, ea.id DESC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Final-Interview eligibility uses the SAME authoritative business rule as the
// rest of HR1 (includes/exam.php): Initial Screening PASSED AND Exam PASSED
// (passed screening status, has a job, and an ACTIVE exam on that job with a
// PASSED result). Delegating to the shared function guarantees the UI and the
// server never diverge.
$ready = array_map(static function (array $r) {
    return applicantFinalInterviewEligible([
        'id'             => (int) $r['applicant_id'],
        'job_posting_id' => (int) $r['applicant_job_id'],
        'status'         => (string) $r['applicant_status'],
    ]);
}, $assignments);

$pageTitle = 'Tracker';
$currentModule = 'applicants';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Tracker</h1>
        <p class="page-subtitle">Centralized examination records and returned results across applicants</p>
    </div>
    <div class="btn-group">
        <a href="<?= BASE_URL ?>/modules/applicants/index.php" class="btn btn-outline">← Back to Applicants</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <div class="panel-header">
        <h2>Filter by Assignment Status</h2>
        <div class="btn-group" style="flex-wrap:wrap;">
            <a href="exam_history.php" class="btn btn-sm <?= $filter === '' ? 'btn-primary' : 'btn-outline' ?>">All <?= count($assignments) ? '(' . count($assignments) . ')' : '' ?></a>
            <?php foreach ($allowedStatuses as $s): ?>
            <a href="exam_history.php?filter=<?= urlencode($s) ?>"
               class="btn btn-sm <?= $filter === $s ? 'btn-primary' : 'btn-outline' ?>">
                <?= e(ucfirst(str_replace('_', ' ', $s))) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="panel fade-in-up" style="animation-delay:.15s">
    <div class="table-wrap">
    <table class="data-table" style="min-width:1000px">
        <thead>
            <tr>
                <th>Applicant No.</th>
                <th>Applicant Name</th>
                <th>Applied Position</th>
                <th>Exam</th>
                <th>Exam Date</th>
                <th>Score</th>
                <th>Percentage</th>
                <th>Result</th>
                <th>Final Interview Eligibility</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($assignments)): ?>
            <tr><td colspan="10" class="empty">
                <?= $filter !== '' ? 'No ' . e(ucfirst(str_replace('_', ' ', $filter))) . ' examination assignments found.' : 'No examination assignments yet.' ?>
            </td></tr>
            <?php else: foreach ($assignments as $i => $row):
                $name = trim($row['first_name'] . ' ' . $row['last_name']);
                $thisReady = $ready[$i];
                $hasResult = $row['result_status'] !== null;
            ?>
            <tr>
                <td><?= e($row['applicant_no']) ?></td>
                <td>
                    <a href="<?= BASE_URL ?>/modules/applicants/view.php?id=<?= (int) $row['applicant_id'] ?>"><?= e($name) ?></a>
                    <div class="text-muted" style="font-size:.72rem"><?= e($row['email']) ?></div>
                </td>
                <td><?= e($row['position_applied']) ?></td>
                <td><?= e($row['exam_title'] ?: 'Requested (awaiting external exam)') ?></td>
                <td><?= $row['result_date'] ? formatDate($row['result_date']) : '—' ?></td>
                <td>
                    <?php if ($hasResult): ?>
                        <?= e((string) $row['result_earned']) ?> / <?= e((string) $row['result_total']) ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($hasResult): ?>
                        <?= e((string) $row['result_percentage']) ?>%
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($hasResult): ?>
                    <span class="badge <?= $row['result_passed'] ? 'badge-success' : 'badge-danger' ?>">
                        <?= $row['result_passed'] ? 'PASSED' : 'FAILED' ?>
                    </span>
                    <?php else: ?>
                    <span class="badge badge-secondary">No Result</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($thisReady): ?>
                    <span class="badge badge-success">READY FOR FINAL INTERVIEW</span>
                    <?php else: ?>
                    <span class="badge badge-secondary">Not Eligible</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <div class="btn-group" style="flex-wrap:nowrap;">
                        <?php if (!$hasResult && empty($row['exam_id'])): ?>
                        <span class="badge badge-secondary">Requested — awaiting external exam</span>
                        <?php else: ?>
                        <a href="<?= BASE_URL ?>/modules/recruitment/exams/record_result.php?assignment_id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline"><?= $hasResult ? 'View Result' : 'Record Result' ?></a>
                        <?php endif; ?>
                        <?php if ($thisReady): ?>
                        <a href="<?= BASE_URL ?>/modules/recruitment/interview_create.php?applicant_id=<?= (int) $row['applicant_id'] ?>" class="btn btn-sm btn-primary">Final Interview</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>

<div class="alert alert-info" style="margin-top:1rem;">

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>