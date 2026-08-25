<?php
require_once __DIR__ . '/../../includes/auth.php';
requireNotApplicant();

require_once __DIR__ . '/../../includes/ai_screening.php';

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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
