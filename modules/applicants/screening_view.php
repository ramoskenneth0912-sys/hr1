<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

require_once __DIR__ . '/../../includes/ai_screening.php';

$applicantId = (int) ($_GET['id'] ?? 0);
if ($applicantId <= 0) {
    flash('danger', 'Invalid applicant ID.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}

$stmt = db()->prepare(
    'SELECT a.*, d.name AS department_name FROM applicants a
     LEFT JOIN departments d ON a.department_id = d.id WHERE a.id = ?'
);
$stmt->execute([$applicantId]);
$applicant = $stmt->fetch();

if (!$applicant) {
    flash('danger', 'Applicant not found.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}

$screening = getScreeningResult($applicantId);

if (!$screening) {
    flash('warning', 'No AI screening results found for this applicant. Please run a screening first.');
    redirect(BASE_URL . '/modules/applicants/view.php?id=' . $applicantId);
}

$scStatus = (string) ($screening['status'] ?? 'analyzed');

$pageTitle = 'AI Screening — ' . $applicant['first_name'] . ' ' . $applicant['last_name'];
$currentModule = 'applicants';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">AI Resume Screening</h1>
        <p class="page-subtitle"><?= e($applicant['first_name'] . ' ' . $applicant['last_name']) ?> — <?= e($applicant['applicant_no']) ?></p>
    </div>
    <div class="btn-group">
        <a href="view.php?id=<?= $applicantId ?>" class="btn btn-outline">Back to Applicant</a>
        <a href="index.php" class="btn btn-outline">Applicant List</a>
    </div>
</div>

<?php if ($scStatus === 'pending'): ?>

<section class="panel fade-in-up" style="animation-delay:.1s;">
    <h2>AI Screening in Progress</h2>
    <p style="color:var(--muted);margin:.25rem 0 0;">A match analysis was just started for this applicant. The result is being calculated and stored server-side — refresh this page in a moment to see it.</p>
</section>

<?php elseif ($scStatus === 'failed'): ?>

<section class="panel fade-in-up" style="animation-delay:.1s;">
    <h2>AI Screening Unavailable</h2>
    <p style="color:var(--muted);margin:.25rem 0 1rem;">An AI match result could not be produced for this applicant. This can happen when the resume cannot be read (unsupported/corrupt file) or the screening service was temporarily unreachable. The application itself is unaffected — only the match analysis is missing.</p>
    <?php if (!empty($screening['error_message'])): ?>
        <div class="alert alert-warning" style="margin:.5rem 0 1rem;">
            <strong>Reason:</strong> <?= e($screening['error_message']) ?>
        </div>
    <?php endif; ?>
    <?php $ocrCheck = ocrAvailabilityCheck(); if (!$ocrCheck['ok']): ?>
        <p style="color:var(--danger);margin:.25rem 0 1rem;"><strong>Server notice:</strong> <?= e($ocrCheck['reason']) ?>. <?= e($ocrCheck['hint']) ?></p>
    <?php endif; ?>
    <form method="post" action="screening.php" style="margin:0;display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">
        <button type="submit" class="btn btn-primary">Re-run AI Screening</button>
    </form>
</section>

<?php else: ?>
<?php
$matched = json_decode($screening['matched_requirements'] ?? '[]', true) ?: [];
$missing = json_decode($screening['missing_requirements'] ?? '[]', true) ?: [];

$recommendationClass = 'badge-secondary';
if ($screening['recommendation'] === 'Strong Match') {
    $recommendationClass = 'badge-success';
} elseif ($screening['recommendation'] === 'Good Match') {
    $recommendationClass = 'badge-info';
} elseif ($screening['recommendation'] === 'Moderate Match') {
    $recommendationClass = 'badge-warning';
} elseif ($screening['recommendation'] === 'Low Match') {
    $recommendationClass = 'badge-danger';
}

/**
 * Sub-category scores are null when the result comes from the remote
 * provider (which returns an overall match only).
 */
$subScores = [
    'Skills Match'        => $screening['skills_score'],
    'Experience Match'    => $screening['experience_score'],
    'Education Match'     => $screening['education_score'],
    'Qualifications Match' => $screening['qualifications_score'],
];

function scoreColor(int $score): string
{
    if ($score >= 80) return 'var(--success)';
    if ($score >= 60) return 'var(--info)';
    if ($score >= 40) return 'var(--warning)';
    return 'var(--danger)';
}
?>

<div class="stats-grid fade-in-up" style="grid-template-columns: repeat(4, 1fr); animation-delay:.05s;">
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-number" style="color:<?= scoreColor((int) $screening['overall_score']) ?>;"><?= (int) $screening['overall_score'] ?>%</span>
            <span class="stat-label">Overall Match</span>
        </div>
    </div>
    <?php foreach ($subScores as $label => $val): ?>
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-number" <?= $val !== null ? 'style="color:' . scoreColor((int) $val) . ';"' : '' ?>><?= $val !== null ? ((int) $val) . '%' : '—' ?></span>
            <span class="stat-label"><?= e($label) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="two-col fade-in-up" style="animation-delay:.1s;">
    <section class="panel">
        <h2>AI Screening Result</h2>
        <div class="screening-result-card">
            <div class="screening-overall">
                <div class="screening-score-circle" style="--score-color: <?= scoreColor((int) $screening['overall_score']) ?>;">
                    <span class="screening-score-value"><?= (int) $screening['overall_score'] ?>%</span>
                </div>
                <div class="screening-rec">
                    <span class="badge <?= $recommendationClass ?>" style="font-size:.85rem;padding:.4rem .9rem;"><?= e($screening['recommendation']) ?></span>
                </div>
            </div>

            <div class="screening-scores-detail">
                <?php foreach ($subScores as $label => $val): ?>
                <div class="screening-score-row">
                    <span class="screening-score-label"><?= e($label) ?></span>
                    <?php if ($val !== null): ?>
                    <div class="progress-bar" style="flex:1;"><div class="progress-fill" style="width:<?= (int) $val ?>%;background:<?= scoreColor((int) $val) ?>;"></div></div>
                    <span class="screening-score-pct"><?= (int) $val ?>%</span>
                    <?php else: ?>
                    <div class="progress-bar" style="flex:1;"><div class="progress-fill" style="width:0%;"></div></div>
                    <span class="screening-score-pct">—</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="panel">
        <h2>Applicant &amp; Position Details</h2>
        <div class="screening-info-list">
            <div class="screening-info-row">
                <span class="screening-info-label">Applicant</span>
                <span class="screening-info-value"><?= e($applicant['first_name'] . ' ' . $applicant['last_name']) ?></span>
            </div>
            <div class="screening-info-row">
                <span class="screening-info-label">Applied Position</span>
                <span class="screening-info-value"><?= e($screening['job_title']) ?></span>
            </div>
            <div class="screening-info-row">
                <span class="screening-info-label">Application Status</span>
                <span class="screening-info-value"><?= statusBadge($applicant['status']) ?></span>
            </div>
            <div class="screening-info-row">
                <span class="screening-info-label">Date Applied</span>
                <span class="screening-info-value"><?= formatDate($applicant['applied_date']) ?></span>
            </div>
            <div class="screening-info-row">
                <span class="screening-info-label">Screened By</span>
                <span class="screening-info-value"><?= e($screening['screened_by'] ?? 'System') ?></span>
            </div>
            <div class="screening-info-row">
                <span class="screening-info-label">Screened At</span>
                <span class="screening-info-value"><?= date('M d, Y h:i A', strtotime($screening['screened_at'])) ?></span>
            </div>
        </div>
    </section>
</div>

<section class="panel fade-in-up" style="animation-delay:.2s;">
    <h2>AI Analysis</h2>
    <div class="screening-analysis"><?= nl2br(e($screening['ai_analysis'] ?? '')) ?></div>
</section>

<div class="two-col fade-in-up" style="animation-delay:.25s;">
    <section class="panel">
        <h2 style="color:var(--success);">Matched Requirements</h2>
        <?php if (empty($matched)): ?>
        <div class="empty" style="padding:1.5rem;text-align:center;color:var(--muted);">No matched requirements identified.</div>
        <?php else: ?>
        <ul class="screening-req-list screening-req-matched">
            <?php foreach ($matched as $item): ?>
            <li>
                <span class="req-icon req-icon-matched">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </span>
                <?= e($item) ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2 style="color:var(--danger);">Missing / Unclear Requirements</h2>
        <?php if (empty($missing)): ?>
        <div class="empty" style="padding:1.5rem;text-align:center;color:var(--muted);">No missing requirements identified.</div>
        <?php else: ?>
        <ul class="screening-req-list screening-req-missing">
            <?php foreach ($missing as $item): ?>
            <li>
                <span class="req-icon req-icon-missing">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </span>
                <?= e($item) ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>

<div class="panel fade-in-up" style="animation-delay:.3s;padding:1rem 1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
        <p style="font-size:.8rem;color:var(--muted);margin:0;">This screening is an automated AI analysis to assist HR in the review process. Final hiring decisions should be made by the HR/Manager.</p>
        <form method="post" action="screening.php" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">
            <button type="submit" class="btn btn-primary btn-sm">Re-run Screening</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>