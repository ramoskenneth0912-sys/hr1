<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

require_once __DIR__ . '/../../includes/ai_screening.php';
require_once __DIR__ . '/../../includes/exam.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT a.*, d.name AS department_name FROM applicants a
     LEFT JOIN departments d ON a.department_id = d.id WHERE a.id = ?'
);
$stmt->execute([$id]);
$applicant = $stmt->fetch();

if (!$applicant) {
    flash('danger', 'Applicant not found.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}

$screening = getScreeningResult($id);

// Load all interview records for this applicant (latest first).
$ivStmt = db()->prepare(
    'SELECT * FROM interviews WHERE applicant_id = ? ORDER BY id DESC'
);
$ivStmt->execute([$id]);
$interviews = $ivStmt->fetchAll();

// The current/latest active interview (scheduled or rescheduled).
$activeInterview = null;
foreach ($interviews as $iv) {
    if (in_array($iv['status'], ['scheduled', 'rescheduled'], true)) {
        $activeInterview = $iv;
        break;
    }
}

// Whether any interview has already been completed (a terminal interview stage).
$hasCompletedInterview = false;
foreach ($interviews as $iv) {
    if ($iv['status'] === 'completed') {
        $hasCompletedInterview = true;
        break;
    }
}

// Stage flags controlling which actions are shown.
$isUndecided        = in_array($applicant['status'], ['new', 'screening', 'shortlisted'], true);
$eligibleToSchedule = in_array($applicant['status'], ['accepted', 'passed_screening'], true);
$canSchedule        = $eligibleToSchedule && $activeInterview === null && !$hasCompletedInterview;

// Formatting helpers for interview date/time.
$formatIvDate = function (array $iv): string {
    return !empty($iv['interview_date']) ? date('F j, Y', strtotime($iv['interview_date'])) : '—';
};
$formatIvTime = function (array $iv): string {
    if (!empty($iv['interview_time'])) {
        return date('g:i A', strtotime($iv['interview_time']));
    }
    return !empty($iv['interview_date']) ? date('g:i A', strtotime($iv['interview_date'])) : '—';
};

$pageTitle = 'Applicant ' . $applicant['applicant_no'];
$currentModule = 'applicants';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title"><?= e($applicant['first_name'] . ' ' . $applicant['last_name']) ?></h1>
        <p class="page-subtitle"><?= e($applicant['applicant_no']) ?> — <?= statusBadge($applicant['status']) ?></p>
    </div>
    <div class="btn-group">
        <?php if ($screening): ?>
        <a href="screening_view.php?id=<?= $id ?>" class="btn btn-primary">View AI Screening</a>
        <?php else: ?>
        <form method="post" action="screening.php" style="margin:0;display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="applicant_id" value="<?= $id ?>">
            <button type="submit" class="btn btn-primary">Run AI Screening</button>
        </form>
        <?php endif; ?>
        <?php if ($canSchedule): ?>
        <a href="schedule_interview.php?id=<?= $id ?>" class="btn btn-primary">Schedule Interview</a>
        <?php endif; ?>
        <?php if ($isUndecided): ?>
        <form method="post" action="status.php" style="margin:0;display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="applicant_id" value="<?= $id ?>">
            <input type="hidden" name="status" value="accepted">
            <button type="submit" class="btn btn-primary">Accept</button>
        </form>
        <form method="post" action="status.php" style="margin:0;display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="applicant_id" value="<?= $id ?>">
            <input type="hidden" name="status" value="rejected">
            <button type="submit" class="btn btn-outline">Reject</button>
        </form>
        <?php endif; ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-outline">Edit</a>
        <a href="index.php" class="btn btn-outline">Back</a>
    </div>
</div>

<section class="panel detail-grid fade-in-up" style="animation-delay:.1s;">
    <div class="detail-item"><label>Email</label><span><?= e($applicant['email']) ?></span></div>
    <div class="detail-item"><label>Phone</label><span><?= e($applicant['phone'] ?: '—') ?></span></div>
    <div class="detail-item"><label>Position</label><span><?= e($applicant['position_applied']) ?></span></div>
    <div class="detail-item"><label>Department</label><span><?= e($applicant['department_name'] ?? '—') ?></span></div>
    <div class="detail-item"><label>Applied Date</label><span><?= formatDate($applicant['applied_date']) ?></span></div>
    <div class="detail-item"><label>Resume</label><span>
        <?php if (!empty($applicant['resume_path'])): ?>
        <a href="<?= BASE_URL ?>/modules/applicants/resume_file.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline">Download Resume</a>
        <?php else: ?>
        —
        <?php endif; ?>
    </span></div>
    <div class="detail-item full-width"><label>Address</label><span><?= e($applicant['address'] ?: '—') ?></span></div>
    <div class="detail-item full-width"><label>Notes</label><span><?= e($applicant['notes'] ?: '—') ?></span></div>
</section>

<?php if ($activeInterview !== null): ?>
<section class="panel fade-in-up" style="animation-delay:.15s;">
    <div class="panel-header">
        <h2>Interview History</h2>
    </div>
    <?php if (count($interviews) > 1): ?>
    <div class="detail-grid">
        <?php foreach ($interviews as $histIv):
            if ($histIv['id'] === $activeInterview['id']) { continue; } ?>
        <div class="detail-item full-width">
            <label>Previous Schedule — <?= statusBadge($histIv['status']) ?></label>
            <span><?= e($formatIvDate($histIv)) ?> · <?= e($formatIvTime($histIv)) ?> · <?= e($histIv['location'] ?: '—') ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="detail-grid">
        <div class="detail-item"><label>Status</label><span><?= statusBadge($activeInterview['status']) ?></span></div>
        <div class="detail-item"><label>Date</label><span><?= e($formatIvDate($activeInterview)) ?></span></div>
        <div class="detail-item"><label>Time</label><span><?= e($formatIvTime($activeInterview)) ?></span></div>
        <div class="detail-item"><label>Location</label><span><?= e($activeInterview['location'] ?: '—') ?></span></div>
        <div class="detail-item"><label>Company</label><span><?= e($activeInterview['company_name'] ?: '—') ?></span></div>
        <div class="detail-item"><label>Address</label><span><?= e($activeInterview['company_address'] ?: '—') ?></span></div>
        <div class="detail-item"><label>Interviewer</label><span><?= e($activeInterview['interviewer'] ?: '—') ?></span></div>
        <?php if (!empty($activeInterview['additional_instructions'])): ?>
        <div class="detail-item full-width"><label>Additional Instructions</label><span><?= nl2br(e($activeInterview['additional_instructions'])) ?></span></div>
        <?php endif; ?>
    </div>
</section>
<?php elseif (count($interviews) > 0): ?>
<section class="panel fade-in-up" style="animation-delay:.15s;">
    <div class="panel-header">
        <h2>Interview History</h2>
    </div>
    <?php if ($hasCompletedInterview): ?>
    <div class="detail-grid">
        <div class="detail-item full-width"><label>Interview Stage</label><span>The interview stage has been completed.</span></div>
    </div>
    <?php endif; ?>
    <div class="detail-grid">
        <?php foreach ($interviews as $histIv): ?>
        <div class="detail-item"><label>Status</label><span><?= statusBadge($histIv['status']) ?></span></div>
        <div class="detail-item"><label>Date</label><span><?= e($formatIvDate($histIv)) ?></span></div>
        <div class="detail-item"><label>Time</label><span><?= e($formatIvTime($histIv)) ?></span></div>
        <div class="detail-item"><label>Location</label><span><?= e($histIv['location'] ?: '—') ?></span></div>
        <div class="detail-item"><label>Company</label><span><?= e($histIv['company_name'] ?: '—') ?></span></div>
        <div class="detail-item"><label>Address</label><span><?= e($histIv['company_address'] ?: '—') ?></span></div>
        <div class="detail-item"><label>Interviewer</label><span><?= e($histIv['interviewer'] ?: '—') ?></span></div>
        <?php if (!empty($histIv['additional_instructions'])): ?>
        <div class="detail-item full-width"><label>Additional Instructions</label><span><?= nl2br(e($histIv['additional_instructions'])) ?></span></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($screening): ?>
<section class="panel fade-in-up" style="animation-delay:.2s;">
    <div class="panel-header">
        <h2>AI Screening Summary</h2>
        <a href="screening_view.php?id=<?= $id ?>" class="btn btn-sm btn-outline">View Full Analysis</a>
    </div>
    <div class="screening-summary">
        <div class="screening-summary-scores">
            <div class="screening-summary-item">
                <span class="screening-summary-label">Overall Match</span>
                <span class="screening-summary-value" style="color:<?= $screening['overall_score'] >= 80 ? 'var(--success)' : ($screening['overall_score'] >= 60 ? 'var(--info)' : ($screening['overall_score'] >= 40 ? 'var(--warning)' : 'var(--danger)')) ?>;"><?= (int) $screening['overall_score'] ?>%</span>
            </div>
            <div class="screening-summary-item">
                <span class="screening-summary-label">Skills</span>
                <span class="screening-summary-value"><?= (int) $screening['skills_score'] ?>%</span>
            </div>
            <div class="screening-summary-item">
                <span class="screening-summary-label">Experience</span>
                <span class="screening-summary-value"><?= (int) $screening['experience_score'] ?>%</span>
            </div>
            <div class="screening-summary-item">
                <span class="screening-summary-label">Education</span>
                <span class="screening-summary-value"><?= (int) $screening['education_score'] ?>%</span>
            </div>
            <div class="screening-summary-item">
                <span class="screening-summary-label">Qualifications</span>
                <span class="screening-summary-value"><?= (int) $screening['qualifications_score'] ?>%</span>
            </div>
        </div>
        <div class="screening-summary-rec">
            <span class="badge <?= $screening['recommendation'] === 'Strong Match' ? 'badge-success' : ($screening['recommendation'] === 'Good Match' ? 'badge-info' : ($screening['recommendation'] === 'Moderate Match' ? 'badge-warning' : 'badge-danger')) ?>" style="font-size:.85rem;padding:.4rem .9rem;"><?= e($screening['recommendation']) ?></span>
            <span class="screening-summary-date">Screened: <?= date('M d, Y h:i A', strtotime($screening['screened_at'])) ?></span>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// === Examination panel (HR1 exam side) ===
$isExamEligible = applicantExamEligible($applicant);
$matchingExams = $isExamEligible ? activeExamsForJob((int) $applicant['job_posting_id']) : [];
$asnRows = db()->prepare(
    'SELECT ea.*, e.title AS exam_title, e.source_provider,
            er.passed AS result_passed, er.percentage AS result_percentage, er.result_status
     FROM exam_assignments ea
     LEFT JOIN exams e ON e.id = ea.exam_id
     LEFT JOIN exam_attempts at ON at.assignment_id = ea.id
     LEFT JOIN exam_results er ON er.attempt_id = at.id
     WHERE ea.applicant_id = ?
     ORDER BY ea.invited_at DESC'
);
$asnRows->execute([$id]);
$applicantAssignments = $asnRows->fetchAll();
?>
<section class="panel fade-in-up" style="animation-delay:.22s;">
    <div class="panel-header">
        <h2>Examination</h2>
        <a href="<?= BASE_URL ?>/modules/recruitment/exams/assign.php?applicant_id=<?= $id ?>" class="btn btn-sm btn-outline">Manage Assignments</a>
    </div>
    <?php if (!$isExamEligible): ?>
    <p style="color:var(--muted);margin:0;">This applicant is not yet eligible for an examination. Eligibility requires passing initial screening (<strong>Accepted</strong> or <strong>Passed Screening</strong>) and being linked to a Job/Position.</p>
    <?php elseif (empty($matchingExams)): ?>
    <p style="color:var(--muted);margin:0;">Eligible — no examination <strong>reference</strong> is defined for the applied position "<?= e($applicant['position_applied']) ?>" yet. HR1 can still represent an <em>exam request</em> for this applicant, which the future external provider (HR3) will fulfil.</p>
    <?php else: ?>
    <p style="color:var(--muted);margin:0 0 .75rem;">Eligible — <?= count($matchingExams) ?> active examination reference(s) available for "<?= e($applicant['position_applied']) ?>".</p>
    <?php endif; ?>

    <?php $finalInterviewEligible = applicantFinalInterviewEligible($applicant); ?>
    <?php if ($finalInterviewEligible): ?>
    <div class="alert alert-success" style="margin-top:.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <span><strong>Final Interview eligible</strong> — this applicant passed their examination.</span>
        <a href="<?= BASE_URL ?>/modules/recruitment/interview_create.php" class="btn btn-sm btn-primary">Schedule Final Interview</a>
    </div>
    <?php endif; ?>

    <?php if (!empty($applicantAssignments)): ?>
    <div class="table-wrap" style="margin-top:1rem;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Examination</th>
                <th>Assignment Status</th>
                <th>Result</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($applicantAssignments as $a): ?>
            <tr>
                <td>
                    <?php if (!empty($a['exam_title'])): ?>
                    <?= e($a['exam_title']) ?> <small style="color:var(--muted)">(<?= e($a['source_provider']) ?>)</small>
                    <?php else: ?>
                    <em>Examination requested (awaiting external provider)</em>
                    <?php endif; ?>
                </td>
                <td><?= statusBadge($a['status']) ?></td>
                <td>
                    <?php if ($a['result_status'] !== null): ?>
                    <span class="badge <?= $a['result_passed'] ? 'badge-success' : 'badge-danger' ?>"><?= $a['result_passed'] ? 'Passed' : 'Failed' ?></span>
                    <span><?= e((string) $a['result_percentage']) ?>%</span>
                    <?php else: ?>
                    <span class="text-muted">No result returned yet</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php elseif ($isExamEligible && !empty($matchingExams)): ?>
    <a href="<?= BASE_URL ?>/modules/recruitment/exams/assign.php?applicant_id=<?= $id ?>" class="btn btn-sm btn-primary" style="margin-top:1rem;">Assign Examination</a>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
