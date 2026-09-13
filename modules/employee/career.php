<?php
/**
 * MY CAREER — Employee Portal landing page for the complete career section.
 * A functional hub that links to each existing career module (goals,
 * performance, competencies, development, trainings, learning, recognition).
 * Session-scoped, employee-only. Reuses the standard page shell — no new UI.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

$user = getCurrentUser();
$employeeId = $user['employee_id'] ?? null;

if (!$employeeId) {
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="page-header fade-in-up"><div><h1 class="page-title">My Career</h1></div></div>';
    echo '<div class="alert alert-warning">No employee profile linked to your account. Please contact HR.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pageTitle = 'My Career';
$currentModule = 'my-career';
$bodyClass = 'page-dashboard';

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">My Career</h1>
        <p class="page-subtitle">Your goals, performance, competencies and development at TRI-M GLOBAL.</p>
    </div>
</div>

<div class="stats-grid stats-grid-3">
    <a href="<?= BASE_URL ?>/modules/employee/goals.php" class="kpi-card fade-in-up" style="text-decoration:none;display:block;">
        <span class="kpi-value">My Goals</span>
        <span class="kpi-label">Set, track and review your personal goals.</span>
        <span class="kpi-link">Open My Goals &rarr;</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/employee/performance.php" class="kpi-card fade-in-up" style="animation-delay:.05s;text-decoration:none;display:block;">
        <span class="kpi-value">My Performance</span>
        <span class="kpi-label">Performance reviews, feedback and ratings.</span>
        <span class="kpi-link">Open My Performance &rarr;</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/employee/competencies.php" class="kpi-card fade-in-up" style="animation-delay:.1s;text-decoration:none;display:block;">
        <span class="kpi-value">My Competencies</span>
        <span class="kpi-label">Competencies that shape your growth.</span>
        <span class="kpi-link">Open My Competencies &rarr;</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/employee/development.php" class="kpi-card fade-in-up" style="animation-delay:.15s;text-decoration:none;display:block;">
        <span class="kpi-value">My Development</span>
        <span class="kpi-label">Development plans and career targets.</span>
        <span class="kpi-link">Open My Development &rarr;</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/employee/trainings.php" class="kpi-card fade-in-up" style="animation-delay:.2s;text-decoration:none;display:block;">
        <span class="kpi-value">My Trainings</span>
        <span class="kpi-label">Assigned trainings and certifications.</span>
        <span class="kpi-link">Open My Trainings &rarr;</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/employee/learning.php" class="kpi-card fade-in-up" style="animation-delay:.25s;text-decoration:none;display:block;">
        <span class="kpi-value">My Learning</span>
        <span class="kpi-label">Learning resources and development content.</span>
        <span class="kpi-link">Open My Learning &rarr;</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/employee/recognition.php" class="kpi-card fade-in-up" style="animation-delay:.3s;text-decoration:none;display:block;">
        <span class="kpi-value">My Recognition</span>
        <span class="kpi-label">Awards and recognition received.</span>
        <span class="kpi-link">Open My Recognition &rarr;</span>
    </a>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>