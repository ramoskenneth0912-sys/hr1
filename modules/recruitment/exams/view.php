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

$assignments = examAssignments($id);

$pageTitle = 'Examination — ' . $exam['title'];
$currentModule = 'exams';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title"><?= e($exam['title']) ?></h1>
        <p class="page-subtitle"><?= e($exam['job_code'] ?? '—') ?> — <?= e($exam['job_title'] ?? 'No job linked') ?></p>
    </div>
    <div class="btn-group">
        <a href="<?= BASE_URL ?>/modules/applicants/index.php" class="btn btn-primary">Assign to Applicant</a>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-outline">Edit</a>
        <a href="index.php" class="btn btn-outline">Examinations</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Examination Information</h2>
    <div class="detail-grid">
        <div class="detail-item"><label>Status</label><span><?= statusBadge($exam['status']) ?></span></div>
        <div class="detail-item"><label>Source Provider</label><span><?= e($exam['source_provider']) ?></span></div>
        <div class="detail-item"><label>External Provider Ref</label><span><?= e($exam['external_ref'] ?: '—') ?></span></div>
        <div class="detail-item"><label>Associated Job</label><span><?= e($exam['job_code'] ?? '—') ?> — <?= e($exam['job_title'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Department</label><span><?= e($exam['department_name'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Passing Score</label><span><?= e((string) $exam['passing_score']) ?>%</span></div>
        <div class="detail-item"><label>Time Limit</label><span><?= (int) $exam['time_limit_minutes'] ?> min</span></div>
        <div class="detail-item"><label>Max Attempts</label><span><?= (int) $exam['max_attempts'] ?></span></div>
        <?php if (!empty($exam['description'])): ?>
        <div class="detail-item full-width"><label>Description</label><span><?= nl2br(e($exam['description'])) ?></span></div>
        <?php endif; ?>
    </div>
</section>

<section class="panel fade-in-up" style="animation-delay:.15s">
    <h2>Assignments &amp; Returned Results</h2>
    <div class="alert alert-info" style="margin-bottom:1rem;">
        HR1 stores only the examination reference and the <strong>returned outcome</strong> (score, percentage, pass/fail) from the external HR3 provider. HR1 does not store HR3's exam content or correct answers.
    </div>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Applicant</th>
                <th>Assignment Status</th>
                <th>Invited</th>
                <th>Result</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($assignments)): ?>
            <tr><td colspan="5" class="empty">No applicants have been assigned this examination yet.</td></tr>
            <?php else: foreach ($assignments as $asn): ?>
            <tr>
                <td>
                    <strong><?= e($asn['first_name'] . ' ' . $asn['last_name']) ?></strong>
                    <br><small><?= e($asn['applicant_no'] . ' — ' . $asn['email']) ?></small>
                </td>
                <td><?= statusBadge($asn['status']) ?></td>
                <td><?= formatDate($asn['invited_at']) ?></td>
                <td>
                    <?php if ($asn['result_status'] !== null): ?>
                    <span class="badge <?= $asn['result_passed'] ? 'badge-success' : 'badge-danger' ?>">
                        <?= $asn['result_passed'] ? 'Passed' : 'Failed' ?>
                    </span>
                    <span><?= e((string) $asn['result_percentage']) ?>%</span>
                    <br><small><?= e(ucfirst((string) $asn['result_status'])) ?></small>
                    <?php else: ?>
                    <span class="text-muted">No result returned yet</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <a href="record_result.php?assignment_id=<?= (int) $asn['id'] ?>" class="btn btn-sm btn-outline">View / Record Result</a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
