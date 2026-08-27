<?php
/**
 * Interview detail view (HR/Admin only, centralized Interview History).
 * Shows the full record of a single interview, including Previous -> Current
 * schedule for rescheduled interviews. Internal notes are shown only to HR.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('danger', 'Invalid interview record.');
    redirect(BASE_URL . '/modules/applicants/interview_history.php');
}

$stmt = db()->prepare(
    'SELECT i.*, a.applicant_no, a.first_name, a.last_name, a.position_applied, a.email,
            j.title AS job_title, d.name AS department_name
     FROM interviews i
     JOIN applicants a ON i.applicant_id = a.id
     LEFT JOIN job_postings j ON i.job_posting_id = j.id
     LEFT JOIN departments d ON d.id = j.department_id
     WHERE i.id = ?'
);
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    flash('danger', 'Interview record not found.');
    redirect(BASE_URL . '/modules/applicants/interview_history.php');
}

$applicantName = $row['first_name'] . ' ' . $row['last_name'];
$fmtTime = function () use ($row): string {
    if (!empty($row['interview_time'])) {
        return date('g:i A', strtotime($row['interview_time']));
    }
    return !empty($row['interview_date']) ? date('g:i A', strtotime($row['interview_date'])) : '—';
};

$pageTitle = 'Interview — ' . $applicantName;
$currentModule = 'applicants';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Interview Detail</h1>
        <p class="page-subtitle"><?= e($row['applicant_no'] . ' — ' . $applicantName) ?> · <?= statusBadge($row['status']) ?></p>
    </div>
    <div class="btn-group">
        <a href="interview_history.php" class="btn btn-outline">← Back to Interview History</a>
    </div>
</div>

<section class="panel detail-grid fade-in-up" style="animation-delay:.1s">
    <div class="detail-item"><label>Status</label><span><?= statusBadge($row['status']) ?></span></div>
    <div class="detail-item"><label>Job Position</label><span><?= e($row['position_applied'] ?: ($row['job_title'] ?? '—')) ?></span></div>
    <div class="detail-item"><label>Department</label><span><?= e($row['department_name'] ?? '—') ?></span></div>
    <div class="detail-item"><label>Applicant Email</label><span><?= e($row['email'] ?? '—') ?></span></div>
</section>

<section class="panel detail-grid fade-in-up" style="animation-delay:.15s">
    <div class="panel-header"><h2>Current Schedule</h2></div>
    <div class="detail-item"><label>Date</label><span><?= date('F j, Y', strtotime($row['interview_date'])) ?></span></div>
    <div class="detail-item"><label>Time</label><span><?= $fmtTime() ?></span></div>
    <div class="detail-item"><label>Location</label><span><?= e($row['location'] ?: '—') ?></span></div>
    <div class="detail-item"><label>Interviewer</label><span><?= e($row['interviewer'] ?: '—') ?></span></div>
    <div class="detail-item"><label>Company</label><span><?= e($row['company_name'] ?: '—') ?></span></div>
    <div class="detail-item"><label>Company Address</label><span><?= e($row['company_address'] ?: '—') ?></span></div>
</section>

<?php if ($row['status'] === 'rescheduled' && !empty($row['previous_date'])): ?>
<section class="panel detail-grid fade-in-up" style="animation-delay:.2s">
    <div class="panel-header"><h2>Previous Schedule</h2></div>
    <div class="detail-item"><label>Date</label><span><?= date('F j, Y', strtotime($row['previous_date'])) ?></span></div>
    <div class="detail-item"><label>Time</label><span><?= $row['previous_time'] ? date('g:i A', strtotime($row['previous_time'])) : (date('g:i A', strtotime($row['previous_date']))) ?></span></div>
    <div class="detail-item"><label>Location</label><span><?= e($row['previous_location'] ?: '—') ?></span></div>
</section>
<?php endif; ?>

<?php if (!empty($row['additional_instructions'])): ?>
<section class="panel detail-grid fade-in-up" style="animation-delay:.25s">
    <div class="detail-item full-width"><label>Additional Instructions</label><span><?= nl2br(e($row['additional_instructions'])) ?></span></div>
</section>
<?php endif; ?>

<?php if (!empty($row['internal_notes'])): ?>
<section class="panel detail-grid fade-in-up" style="animation-delay:.25s">
    <div class="detail-item full-width"><label>Internal Notes <small style="color:var(--muted);font-weight:normal;">(Private — HR only)</small></label><span><?= nl2br(e($row['internal_notes'])) ?></span></div>
</section>
<?php endif; ?>

<?php if (!empty($row['remarks'])): ?>
<section class="panel detail-grid fade-in-up" style="animation-delay:.25s">
    <div class="detail-item full-width"><label>Remarks</label><span><?= nl2br(e($row['remarks'])) ?></span></div>
</section>
<?php endif; ?>

<section class="panel detail-grid fade-in-up" style="animation-delay:.3s">
    <div class="detail-item"><label>Created</label><span><?= date('M d, Y h:i A', strtotime($row['created_at'])) ?></span></div>
    <div class="detail-item"><label>Last Updated</label><span><?= date('M d, Y h:i A', strtotime($row['updated_at'])) ?></span></div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
