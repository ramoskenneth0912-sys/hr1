<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security_headers.php';

if (isLoggedIn() && isEmployee()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$locationFilter = trim($_GET['location'] ?? '');
$categoryFilter = $_GET['category'] ?? '';

$departments = db()->query(
    'SELECT id, name FROM departments ORDER BY name'
)->fetchAll();

$locations = db()->query(
    "SELECT DISTINCT work_location FROM job_postings
     WHERE status = 'open' AND work_location IS NOT NULL AND work_location <> ''
     ORDER BY work_location"
)->fetchAll(PDO::FETCH_COLUMN);

$sql = 'SELECT j.*, d.name AS department_name
        FROM job_postings j
        LEFT JOIN departments d ON j.department_id = d.id
        WHERE j.status = ?';
$params = ['open'];

if ($search !== '') {
    $sql .= ' AND (j.title LIKE ? OR j.description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($locationFilter !== '') {
    $sql .= ' AND j.work_location = ?';
    $params[] = $locationFilter;
}
if ($categoryFilter !== '') {
    $sql .= ' AND j.department_id = ?';
    $params[] = $categoryFilter;
}

$sql .= ' ORDER BY j.posted_date DESC, j.id ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

$totalOpen = count($jobs);
$hasFilters = ($search !== '' || $locationFilter !== '' || $categoryFilter !== '');

$icons = [
    'briefcase' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
    'pin'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
    'monitor'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
    'calendar'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'users'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Jobs - <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <style>
        :root { --pub-brand: #43109F; --pub-brand-dark: #36088C; --pub-footer: #351286; }
        html { scroll-behavior: smooth; }
        body {
            background: linear-gradient(180deg, #FAF8FE 0%, #FFFFFF 480px);
            font-family: 'Inter', sans-serif;
            color: #2A2153;
        }
        .bg-decor { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
        .bg-decor::before, .bg-decor::after { content: ''; position: absolute; border-radius: 50%; }
        .bg-decor::before {
            width: 620px; height: 620px; top: -220px; right: -160px;
            background: radial-gradient(circle at center, rgba(67,16,159,.065), transparent 66%);
        }
        .bg-decor::after {
            width: 700px; height: 700px; bottom: -280px; left: -220px;
            background: radial-gradient(circle at center, rgba(67,16,159,.05), transparent 66%);
        }
        @media (max-width: 640px) {
            .bg-decor::before { width: 380px; height: 380px; }
            .bg-decor::after { width: 420px; height: 420px; }
        }
        .public-header {
            background: rgba(255,255,255,.94);
            border-bottom: 1px solid #ECE9F4;
            padding: 0 clamp(20px, 3.3vw, 51px);
            min-height: 96px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .public-header .brand { display: inline-flex; align-items: center; text-decoration: none; }
        .public-header .brand img { height: 68px; width: auto; display: block; }
        .header-right { display: flex; align-items: center; gap: 26px; }
        .main-nav { display: flex; align-items: center; gap: 30px; }
        .main-nav a {
            position: relative;
            font-size: 14.5px;
            font-weight: 500;
            color: #3F3D56;
            text-decoration: none;
            padding: 6px 2px;
            transition: color .2s ease;
        }
        .main-nav a::after {
            content: '';
            position: absolute;
            left: 2px; right: 100%; bottom: 0;
            height: 2px; border-radius: 2px;
            background: var(--pub-brand);
            transition: right .25s ease;
        }
        .main-nav a:hover { color: var(--pub-brand); }
        .main-nav a:hover::after, .main-nav a.active::after { right: 2px; }
        .main-nav a.active { color: var(--pub-brand); font-weight: 600; }
        .main-nav a:focus-visible, .btn-login:focus-visible {
            outline: 2px solid var(--pub-brand);
            outline-offset: 3px;
            border-radius: 4px;
        }
        .btn-login {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 44px;
            padding: 0 28px;
            background: var(--pub-brand);
            color: #fff;
            font-size: 14.5px;
            font-weight: 600;
            border-radius: 10px;
            text-decoration: none;
            transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
        }
        .btn-login:hover {
            background: var(--pub-brand-dark);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(67,16,159,.22);
        }
        .nav-toggle { display: none; background: none; border: 0; cursor: pointer; padding: 8px; }
        .nav-toggle span { display: block; width: 22px; height: 2px; background: #12102E; margin: 5px 0; border-radius: 2px; transition: transform .25s ease, opacity .25s ease; }
        .mobile-nav { display: none; }
        @media (max-width: 860px) {
            .main-nav { display: none; }
            .nav-toggle { display: block; }
            .mobile-nav[hidden] { display: none; }
            .mobile-nav { display: block; position: absolute; top: 100%; left: 0; right: 0; background: #fff; border-bottom: 1px solid #E7E5EE; padding: 10px clamp(20px, 3.3vw, 51px) 18px; box-shadow: 0 14px 24px rgba(18,16,46,.08); }
            .mobile-nav a { display: block; padding: 12px 2px; font-size: 15px; font-weight: 500; color: #3F3D56; text-decoration: none; border-bottom: 1px solid #F1EFF7; }
            .mobile-nav a:last-child { border-bottom: 0; }
        }
        @media (max-width: 560px) {
            .public-header .btn-login { display: none; }
        }
        .browse-container {
            max-width: 960px;
            margin: 0 auto;
            padding: 2.6rem 1.5rem 3rem;
            position: relative;
            z-index: 1;
        }
        .browse-head { margin-bottom: 2rem; animation: browseIn .55s ease both; }
        .browse-head h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #241C4F;
            letter-spacing: -0.02em;
            margin-bottom: .45rem;
        }
        .browse-head p {
            color: #6E7391;
            font-size: .925rem;
            display: inline-flex;
            align-items: center;
            gap: .5rem;
        }
        .browse-head p::before {
            content: '';
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--pub-brand);
            box-shadow: 0 0 0 3px rgba(67,16,159,.14);
            flex-shrink: 0;
        }
        .job-list { display: grid; gap: 16px; }
        .job-row {
            background: #fff;
            border: 1px solid #ECE9F4;
            border-radius: 14px;
            padding: 24px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            box-shadow: 0 1px 2px rgba(18,16,46,.04), 0 14px 34px -22px rgba(67,16,159,.20);
            transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
            animation: browseIn .55s ease both;
        }
        .job-row:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 10px rgba(18,16,46,.05), 0 24px 48px -22px rgba(67,16,159,.30);
            border-color: rgba(67,16,159,.30);
        }
        .job-row-main { min-width: 0; }
        .job-row h3 {
            font-size: 1.13rem;
            font-weight: 700;
            color: #241C4F;
            letter-spacing: -.01em;
            margin-bottom: .45rem;
        }
        .job-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .5rem 1.15rem;
            font-size: .8rem;
            color: #6E7391;
            margin-bottom: .6rem;
        }
        .meta-item { display: inline-flex; align-items: center; gap: .38rem; white-space: nowrap; }
        .meta-item svg { width: 14px; height: 14px; flex-shrink: 0; opacity: .85; }
        .job-excerpt {
            font-size: .87rem;
            color: #4B4A68;
            line-height: 1.68;
            max-width: 62ch;
        }
        .job-row-action { flex-shrink: 0; }
        .job-row-action .btn.btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 22px;
            border-radius: 10px;
            background: var(--pub-brand);
            color: #fff;
            font-weight: 600;
            font-size: .875rem;
            letter-spacing: .01em;
            box-shadow: 0 4px 14px -4px rgba(67,16,159,.45);
            transition: transform .22s ease, box-shadow .22s ease, filter .22s ease;
        }
        .job-row-action .btn.btn-primary:hover {
            transform: translateY(-2px);
            filter: brightness(1.07);
            box-shadow: 0 10px 24px -6px rgba(67,16,159,.52);
        }
        .job-row-action .btn:focus-visible {
            outline: 2px solid var(--pub-brand-dark);
            outline-offset: 3px;
        }
        .browse-empty {
            background: var(--purple-bg, #F5F0FB);
            border: 1px dashed rgba(67,16,159,.35);
            border-radius: 14px;
            padding: 2.2rem;
            text-align: center;
            color: var(--muted, #6B7280);
        }
        .search-panel {
            display: flex;
            gap: 12px;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .search-panel input,
        .search-panel select {
            height: 44px;
            padding: 0 14px;
            border: 1px solid #ECE9F4;
            border-radius: 8px;
            background: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            color: #2A2153;
            transition: border-color .25s ease, box-shadow .25s ease;
        }
        .search-panel input { flex: 2; min-width: 180px; }
        .search-panel select { flex: 1; min-width: 140px; cursor: pointer; }
        .search-panel input:focus,
        .search-panel select:focus {
            outline: none;
            border-color: #A87FE8;
            box-shadow: 0 0 0 3px rgba(67,16,159,.1);
        }
        .btn-search {
            height: 44px;
            padding: 0 32px;
            background: var(--pub-brand);
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background .2s ease;
        }
        .btn-search:hover { background: var(--pub-brand-dark); }
        .clear-filters {
            align-self: center;
            font-size: 14px;
            font-weight: 600;
            color: var(--pub-brand);
            text-decoration: none;
            padding: 8px 4px;
            white-space: nowrap;
        }
        .clear-filters:hover { text-decoration: underline; }
        @media (max-width: 640px) {
            .search-panel { flex-direction: column; }
            .search-panel input, .search-panel select { flex: 1 1 100%; }
            .btn-search { width: 100%; }
        }
        @keyframes browseIn {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .browse-head, .job-row { animation: none; }
            * { transition-duration: .01ms !important; }
        }
        @media (max-width: 640px) {
            .job-row { flex-direction: column; align-items: stretch; }
            .job-row-action .btn { width: 100%; }
        }
        .public-footer {
            background: var(--pub-footer);
            color: rgba(255,255,255,.75);
            text-align: center;
            padding: 22px 16px;
            font-size: 12.5px;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="bg-decor" aria-hidden="true"></div>
    <header class="public-header">
        <a href="<?= BASE_URL ?>/public/jobs.php" class="brand" aria-label="TRI-M Global Logistics &amp; Trading Inc. — Home">
            <img src="../assets/images/tri-m-logo.png" alt="TRI-M GLOBAL — Logistics &amp; Trading Inc.">
        </a>
        <div class="header-right">
            <nav class="main-nav" aria-label="Main navigation">
                <a href="<?= BASE_URL ?>/public/jobs.php">Home</a>
                <a href="<?= BASE_URL ?>/public/browse-jobs.php" class="active">Browse Jobs</a>
                <a href="<?= BASE_URL ?>/public/jobs.php#about">About Us</a>
                <a href="<?= BASE_URL ?>/public/jobs.php#contact">Contact Us</a>
            </nav>
            <?php if (isLoggedIn()): ?>
                <a href="<?= BASE_URL ?>/index.php" class="btn-login">Dashboard</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/auth/login.php" class="btn-login">Log In</a>
            <?php endif; ?>
            <button type="button" class="nav-toggle" id="navToggle" aria-expanded="false" aria-controls="mobileNav" aria-label="Toggle menu">
                <span></span><span></span><span></span>
            </button>
        </div>
        <nav class="mobile-nav" id="mobileNav" hidden aria-label="Mobile navigation">
            <a href="<?= BASE_URL ?>/public/jobs.php">Home</a>
            <a href="<?= BASE_URL ?>/public/browse-jobs.php">Browse Jobs</a>
            <a href="<?= BASE_URL ?>/public/jobs.php#about">About Us</a>
            <a href="<?= BASE_URL ?>/public/jobs.php#contact">Contact Us</a>
            <?php if (isLoggedIn()): ?>
                <a href="<?= BASE_URL ?>/index.php">Dashboard</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/auth/login.php">Log In</a>
            <?php endif; ?>
        </nav>
    </header>

    <div class="browse-container">
        <div class="browse-head">
            <h1>Available Job Opportunities</h1>
            <p><?= $totalOpen ?> open position<?= $totalOpen === 1 ? '' : 's' ?> — select a job to view full details and apply.</p>
        </div>

        <form method="get" action="browse-jobs.php" class="search-panel">
            <input type="text" name="search" placeholder="Search by job title or keyword" value="<?= e($search) ?>" aria-label="Search by job title or keyword">
            <select name="location" aria-label="Location">
                <option value="">All Locations</option>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= e($loc) ?>" <?= $locationFilter === $loc ? 'selected' : '' ?>><?= e($loc) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="category" aria-label="Job Category">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= e((string)$dept['id']) ?>" <?= $categoryFilter == $dept['id'] ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-search">Search</button>
            <?php if ($hasFilters): ?>
                <a href="browse-jobs.php" class="clear-filters">Clear &times;</a>
            <?php endif; ?>
        </form>

        <?php if (empty($jobs)): ?>
            <div class="browse-empty">
                <?php if ($hasFilters): ?>
                    No jobs match your search criteria. <a href="browse-jobs.php" style="color:var(--pub-brand);font-weight:600;">Clear filters</a> to see all open positions.
                <?php else: ?>
                    There are no open positions right now. Please check back soon.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="job-list">
                <?php foreach ($jobs as $i => $job): ?>
                    <div class="job-row" style="animation-delay: <?= number_format($i * 0.06, 2) ?>s">
                        <div class="job-row-main">
                            <h3><?= e($job['title']) ?></h3>
                            <div class="job-meta">
                                <span class="meta-item"><?= $icons['briefcase'] ?><?= e($job['department_name'] ?? 'N/A') ?></span>
                                <span class="meta-item"><?= $icons['pin'] ?><?= e($job['work_location'] ?? 'N/A') ?></span>
                                <span class="meta-item"><?= $icons['monitor'] ?><?= e(ucfirst((string)$job['job_employment_type'])) ?></span>
                                <span class="meta-item"><?= $icons['calendar'] ?>Posted <?= formatDate($job['posted_date']) ?></span>
                                <?php if (!empty($job['vacancies'])): ?>
                                    <span class="meta-item"><?= $icons['users'] ?><?= (int)$job['vacancies'] ?> vacanc<?= $job['vacancies'] == 1 ? 'y' : 'ies' ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($job['description'])): ?>
                                <p class="job-excerpt"><?= e(mb_strimwidth(strip_tags((string)$job['description']), 0, 160, '…')) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="job-row-action">
                            <a href="job_detail.php?id=<?= (int)$job['id'] ?>" class="btn btn-primary">View Details &amp; Apply</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <footer class="public-footer">
        &copy; <?= date('Y') ?> TRI-M Global Logistics &amp; Trading Inc. All rights reserved.
    </footer>

    <script>
    (function () {
        var toggle = document.getElementById('navToggle');
        var mobileNav = document.getElementById('mobileNav');
        if (toggle && mobileNav) {
            toggle.addEventListener('click', function () {
                var open = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', String(!open));
                mobileNav.hidden = open;
            });
            mobileNav.addEventListener('click', function (e) {
                if (e.target.tagName === 'A') {
                    toggle.setAttribute('aria-expanded', 'false');
                    mobileNav.hidden = true;
                }
            });
        }
    })();
    </script>
</body>
</html>
