<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security_headers.php';

$pageTitle = $pageTitle ?? APP_NAME;
$currentModule = $currentModule ?? '';
$bodyClass = $bodyClass ?? '';

$currentUser = getCurrentUser();
$userRole = getUserRole();

$hour = (int) date('G');
if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

$navSections = [
    'MAIN' => [
        ['id' => 'dashboard', 'label' => 'Dashboard', 'url' => BASE_URL . '/index.php', 'icon' => 'grid'],
    ],
];

if (isHRorManager()) {
    $navSections['MAIN'][0]['url'] = BASE_URL . '/index.php';
    $navSections['RECRUITMENT & HR'] = [
        ['id' => 'recruitment', 'label' => 'Recruitment', 'url' => BASE_URL . '/modules/recruitment/index.php', 'icon' => 'briefcase'],
        ['id' => 'applicants', 'label' => 'Applicants', 'url' => BASE_URL . '/modules/applicants/index.php', 'icon' => 'users'],
        ['id' => 'onboarding', 'label' => 'Onboarding', 'url' => BASE_URL . '/modules/onboarding/index.php', 'icon' => 'checklist'],
        ['id' => 'hcm', 'label' => 'Core HCM', 'url' => BASE_URL . '/modules/hcm/index.php', 'icon' => 'building'],
        ['id' => 'records', 'label' => 'Records', 'url' => BASE_URL . '/modules/records/index.php', 'icon' => 'folder'],
    ];
    $navSections['SYSTEM'] = [
        ['id' => 'users', 'label' => 'User Management', 'url' => BASE_URL . '/modules/users/index.php', 'icon' => 'users'],
        ['id' => 'settings', 'label' => 'Settings', 'url' => BASE_URL . '/modules/settings/index.php', 'icon' => 'settings'],
        ['id' => 'password-resets', 'label' => 'Password Resets', 'url' => BASE_URL . '/modules/settings/password_resets.php', 'icon' => 'key'],
    ];
} elseif (isEmployee()) {
    $navSections['MAIN'] = [
        ['id' => 'dashboard', 'label' => 'Dashboard', 'url' => BASE_URL . '/index.php', 'icon' => 'grid'],
    ];
    $navSections['MY ACCOUNT'] = [
        ['id' => 'my-profile', 'label' => 'My Profile', 'url' => BASE_URL . '/modules/employee/profile.php', 'icon' => 'user'],
        ['id' => 'my-documents', 'label' => 'My Documents', 'url' => BASE_URL . '/modules/employee/documents.php', 'icon' => 'folder'],
    ];
    $navSections['MY WORK'] = [
        ['id' => 'leave-requests', 'label' => 'Leave Requests', 'url' => BASE_URL . '/modules/employee/leave.php', 'icon' => 'checklist'],
    ];
    $navSections['COMMUNICATION'] = [
        ['id' => 'notifications', 'label' => 'Notifications', 'url' => BASE_URL . '/modules/employee/notifications.php', 'icon' => 'bell'],
    ];
    $navSections['SYSTEM'] = [
        ['id' => 'settings', 'label' => 'Settings', 'url' => BASE_URL . '/modules/employee/settings.php', 'icon' => 'settings'],
    ];
} elseif (isApplicant()) {
    $navSections['MAIN'] = [
        ['id' => 'applicant-dashboard', 'label' => 'My Applications', 'url' => BASE_URL . '/modules/applicant/dashboard.php', 'icon' => 'grid'],
        ['id' => 'applicant-exams', 'label' => 'My Examinations', 'url' => BASE_URL . '/modules/applicant/exams.php', 'icon' => 'checklist'],
    ];
    $navSections['JOBS'] = [
        ['id' => 'jobs', 'label' => 'Browse Jobs', 'url' => BASE_URL . '/public/jobs.php', 'icon' => 'briefcase'],
    ];
}

$showSidebar = isLoggedIn() && (isHRorManager() || isEmployee() || isApplicant());

// Notifications for the header bell — always scoped to the logged-in account.
// The dropdown body is rendered by the shared helper so that it stays
// byte-for-byte identical to what the AJAX polling endpoint returns.
$notifData = isLoggedIn() ? notification_data((int) $_SESSION['user_id']) : ['count' => 0, 'items' => []];
$notifCount = (int) $notifData['count'];
$notifItems = $notifData['items'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=5">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tailwind.css">
</head>
<body class="<?= e($bodyClass) ?>">
<div class="app-layout<?= $showSidebar ? ' has-sidebar' : '' ?>">
    <?php if ($showSidebar): ?>
    <aside class="sidebar" id="appSidebar">
<div class="sidebar-brand">
              <a href="<?= e($navSections['MAIN'][0]['url']) ?>">
                  <img class="brand-logo" src="<?= BASE_URL ?>/assets/images/dashboard%20logo.png" alt="Tri-M Global logo">
                  <span class="brand-names">
                      <span class="brand-name">Tri-M Global</span>
                      <span class="brand-sub">Logistics &amp; Trading Inc.</span>
                  </span>
              </a>
          </div>

        <nav class="sidebar-nav">
            <?php foreach ($navSections as $section => $items): ?>
            <div class="nav-section">
                <span class="nav-section-label"><?= e($section) ?></span>
                <?php foreach ($items as $item): ?>
                <a href="<?= e($item['url']) ?>" class="nav-link <?= $currentModule === $item['id'] ? 'active' : '' ?>">
                    <span class="nav-icon nav-icon-<?= e($item['icon']) ?>"></span>
                    <span><?= e($item['label']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <span class="sidebar-version"><?= isHRorManager() ? 'HR Admin' : (isEmployee() ? 'Employee' : 'Applicant') ?> · Core HR v1.0</span>
        </div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>
    <?php endif; ?>

    <div class="main-wrapper">
        <div class="dashboard-logo-bg" aria-hidden="true">
            <img src="<?= BASE_URL ?>/assets/images/tri-m-logo.png" alt="">
        </div>
        <header class="topbar">
            <?php if ($showSidebar): ?>
            <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Open navigation" aria-expanded="false" aria-controls="appSidebar">
                <span class="burger-box" aria-hidden="true"><span></span><span></span><span></span></span>
            </button>
            <?php endif; ?>
            <div class="topbar-search">
                <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" placeholder="Search modules, employees, applicants..." aria-label="Search">
            </div>

            <div class="topbar-actions">
                <span class="live-badge">
                    <span class="live-dot"></span> Live
                </span>
                <?php if (isLoggedIn()): ?>
                <div class="notif-wrap">
                    <button type="button" class="icon-btn" id="notifToggle" aria-label="Notifications" aria-expanded="false">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        <?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount > 9 ? '9+' : $notifCount ?></span><?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown" hidden><?= notification_dropdown_html(isLoggedIn() ? (int) $_SESSION['user_id'] : null) ?></div>
                </div>
                <?php endif; ?>
                <?php if (isLoggedIn() && $currentUser): ?>
                <div class="user-profile">
                    <span class="user-avatar"><?= e(substr($currentUser['first_name'] ?? $currentUser['username'], 0, 2)) ?></span>
                    <div class="user-info">
                        <strong><?= e(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? $currentUser['username'])) ?></strong>
                        <span><?= e(ucfirst(getUserRole())) ?></span>
                    </div>
                    <form method="post" action="<?= BASE_URL ?>/auth/logout.php" style="display:inline;margin-left:.5rem;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline" data-confirm-logout>Logout</button>
                    </form>
                </div>
                <?php else: ?>
                <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary btn-sm">Log In</a>
                <?php endif; ?>
            </div>
        </header>

        <main class="container">
            <?php $flash = getFlash(); if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>
