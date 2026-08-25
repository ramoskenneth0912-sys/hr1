<?php
require_once __DIR__ . '/../../includes/auth.php';
requireApplicant();

$currentUser = getCurrentUser();
$applicantEmail = $currentUser['email'] ?? '';

$stmt = db()->prepare(
    'SELECT a.*, d.name AS department_name
     FROM applicants a
     LEFT JOIN departments d ON a.department_id = d.id
     WHERE a.user_id = ? OR a.email = ?
     ORDER BY a.created_at DESC'
);
$stmt->execute([$_SESSION['user_id'], $applicantEmail]);
$applications = $stmt->fetchAll();

$openJobs = (int) db()->query("SELECT COUNT(*) FROM job_postings WHERE status = 'open'")->fetchColumn();

$pageTitle = 'My Applications';
$currentModule = 'applicant-dashboard';
$bodyClass = 'page-dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Welcome, <?= e($currentUser['first_name'] ?? $currentUser['username']) ?>!</h1>
        <p class="page-subtitle">Applicant Portal</p>
    </div>
    <div class="page-actions">
        <a href="<?= BASE_URL ?>/public/jobs.php" class="btn btn-primary">Browse Open Jobs</a>
        <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-outline">Logout</a>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(2, 1fr);">
    <div class="stat-card fade-in-up" style="animation-delay:.1s">
        <div class="stat-info">
            <span class="stat-number"><?= count($applications) ?></span>
            <span class="stat-label">My Applications</span>
        </div>
    </div>
    <div class="stat-card fade-in-up" style="animation-delay:.2s">
        <div class="stat-info">
            <span class="stat-number"><?= $openJobs ?></span>
            <span class="stat-label">Open Positions</span>
        </div>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.3s">
    <h2>My Applications</h2>
    <?php if (empty($applications)): ?>
    <div class="empty" style="padding:2rem;text-align:center;color:var(--muted);">
        <p>You haven't applied to any positions yet.</p>
        <a href="<?= BASE_URL ?>/public/jobs.php" class="btn btn-primary" style="margin-top:1rem;">Browse Open Jobs</a>
    </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Application No.</th>
                <th>Position</th>
                <th>Department</th>
                <th>Date Applied</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($applications as $app): ?>
            <tr>
                <td><?= e($app['applicant_no']) ?></td>
                <td><?= e($app['position_applied']) ?></td>
                <td><?= e($app['department_name'] ?? 'N/A') ?></td>
                <td><?= formatDate($app['applied_date']) ?></td>
                <td><?= statusBadge($app['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
