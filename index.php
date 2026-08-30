<?php
$pageTitle = 'Dashboard';
$currentModule = 'dashboard';
$bodyClass = 'page-dashboard';
require_once __DIR__ . '/includes/auth.php';

// Role guards must run BEFORE any output (header.php streams HTML).
// Unauthenticated visitors must not see this page — redirect them to login.
requireLogin();

if (isApplicant()) {
    redirect(BASE_URL . '/modules/applicant/dashboard.php');
}

require_once __DIR__ . '/includes/header.php';

// ---------------------------------------------------------------------------
// EMPLOYEE SELF-SERVICE DASHBOARD — same design system, employee-scoped data.
// ---------------------------------------------------------------------------
if (isEmployee()) {
    $user = getCurrentUser();
    $employeeId = $user['employee_id'] ?? null;
    $uid = (int) $_SESSION['user_id'];

    $pendingLeave = 0;
    $docCount = 0;
    if ($employeeId) {
        $s = db()->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'pending'");
        $s->execute([$employeeId]);
        $pendingLeave = (int) $s->fetchColumn();

        $s = db()->prepare('SELECT COUNT(*) FROM employee_documents WHERE employee_id = ?');
        $s->execute([$employeeId]);
        $docCount = (int) $s->fetchColumn();
    }

    // Unread notifications count for this account (summary only — list lives in Notifications).
    $s = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $s->execute([$uid]);
    $unreadNotifs = (int) $s->fetchColumn();
?>

<section class="welcome-banner fade-in-up">
    <div class="welcome-content">
        <p class="welcome-greeting"><?= e($greeting) ?>, <?= e(($user['first_name'] ?? '') ?: $user['username']) ?>!</p>
        <h1 class="welcome-title">My Workspace</h1>
        <p class="welcome-subtitle">Employee Self-Service — <?= e($user['job_title'] ?? 'Employee') ?><?= isset($user['department_name']) && $user['department_name'] ? ' · ' . e($user['department_name']) : '' ?></p>
    </div>
    <div class="welcome-status">
        <span class="status-label">System Status</span>
        <span class="status-value"><span class="live-dot"></span> Synced</span>
    </div>
</section>

<div class="stats-grid">
    <div class="kpi-card fade-in-up" style="animation-delay:.1s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-teal"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg></span>
        </div>
        <span class="kpi-value"><?= $pendingLeave ?></span>
        <span class="kpi-label">Pending Leave Requests</span>
        <a href="<?= BASE_URL ?>/modules/employee/leave.php" class="kpi-link">View Leave Requests &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.4s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-orange"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
        </div>
        <span class="kpi-value"><?= $docCount ?></span>
        <span class="kpi-label">My Documents</span>
        <a href="<?= BASE_URL ?>/modules/employee/documents.php" class="kpi-link">Open &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.5s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-purple"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>
        </div>
        <span class="kpi-value"><?= $unreadNotifs ?></span>
        <span class="kpi-label">Unread Notifications</span>
        <a href="<?= BASE_URL ?>/modules/employee/notifications.php" class="kpi-link">View Notifications &rarr;</a>
    </div>
</div>

<?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// HR / ADMIN DASHBOARD — management overview in the same visual format as the
// Employee dashboard (hero banner + KPI cards), populated with HR data only.
// ---------------------------------------------------------------------------
if (isHRorManager()) {
    $user = getCurrentUser();

    $openJobs   = (int) db()->query("SELECT COUNT(*) FROM job_postings WHERE status = 'open'")->fetchColumn();
    $applicants = (int) db()->query('SELECT COUNT(*) FROM applicants')->fetchColumn();
    $onboarding = (int) db()->query("SELECT COUNT(*) FROM employee_onboarding WHERE status <> 'completed'")->fetchColumn();
    $pendingReq = (int) db()->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
    $hrRecords  = (int) db()->query('SELECT COUNT(*) FROM employee_documents')->fetchColumn();
?>

<section class="welcome-banner fade-in-up">
    <div class="welcome-content">
        <p class="welcome-greeting"><?= e($greeting) ?>, <?= e(($user['first_name'] ?? '') ?: $user['username']) ?>!</p>
        <h1 class="welcome-title">HR &amp; Administration Workspace</h1>
        <p class="welcome-subtitle">Recruitment, Human Resources &amp; Employee Management</p>
    </div>
    <div class="welcome-status">
        <span class="status-label">System Status</span>
        <span class="status-value"><span class="live-dot"></span> Synced</span>
    </div>
</section>

<div class="stats-grid stats-grid-3">
    <div class="kpi-card fade-in-up" style="animation-delay:.1s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-purple"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></span>
        </div>
        <span class="kpi-value"><?= $openJobs ?></span>
        <span class="kpi-label">Active Recruitment</span>
        <a href="<?= BASE_URL ?>/modules/recruitment/index.php" class="kpi-link">View Recruitment &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.15s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-blue"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
        </div>
        <span class="kpi-value"><?= $applicants ?></span>
        <span class="kpi-label">Applicants</span>
        <a href="<?= BASE_URL ?>/modules/applicants/index.php" class="kpi-link">View Applicants &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.25s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-orange"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
        </div>
        <span class="kpi-value"><?= $onboarding ?></span>
        <span class="kpi-label">Onboarding</span>
        <a href="<?= BASE_URL ?>/modules/onboarding/index.php" class="kpi-link">View Onboarding &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.3s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-purple"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
        </div>
        <span class="kpi-value"><?= $pendingReq ?></span>
        <span class="kpi-label">Pending Requests</span>
        <a href="<?= BASE_URL ?>/modules/ess/index.php" class="kpi-link">View Requests &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.35s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-blue"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span>
        </div>
        <span class="kpi-value"><?= $hrRecords ?></span>
        <span class="kpi-label">HR Records</span>
        <a href="<?= BASE_URL ?>/modules/records/index.php" class="kpi-link">View Records &rarr;</a>
    </div>
</div>

<?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
?>
