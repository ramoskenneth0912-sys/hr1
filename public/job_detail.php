<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security_headers.php';

/**
 * This file is the single source of truth for the applicant-facing job posting
 * page. It is used in two ways:
 *
 *   1. Directly (public): fetches the saved job record by id and renders it.
 *   2. As a view include (admin preview): modules/recruitment/job_preview.php
 *      sets $job from the unsaved form data (and $preview = true) before
 *      including this file, so HR sees exactly what applicants will see.
 *
 * The preview therefore reuses the exact same rendering — there is no separate
 * preview implementation.
 */
$preview = (bool) ($preview ?? false);

if (!isset($job)) {
    if (isLoggedIn() && isEmployee() && !maintenance_is_active()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id <= 0) {
        redirect(BASE_URL . '/public/jobs.php');
    }

    $stmt = db()->prepare('SELECT j.*, d.name AS department_name FROM job_postings j LEFT JOIN departments d ON j.department_id = d.id WHERE j.id = ?');
    $stmt->execute([$id]);
    $job = $stmt->fetch();

    if (!$job) {
        redirect(BASE_URL . '/public/jobs.php');
    }

    // Only 'open' and 'closed' postings belong to the public workflow. Drafts
    // and other internal statuses are not exposed (matches the open-only list).
    if (!in_array($job['status'], ['open', 'closed'], true)) {
        redirect(BASE_URL . '/public/browse-jobs.php');
    }
}

$isOpen = (($job['status'] ?? 'closed') === 'open');
$applyHref = $preview ? '#preview' : 'apply.php?id=' . (int) ($job['id'] ?? 0);

$icons = [
    'briefcase' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
    'pin'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
    'monitor'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
    'calendar'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'clock'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    'users'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'tag'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
    'file'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
    'mail'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>',
    'check'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
    'money'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>',
    'chevron'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>',
    'eye'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($job['title'] ?? '') ?> - <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/tailwind.css">
    <style>
        :root { --pub-brand: #43109F; --pub-brand-dark: #36088C; --pub-footer: #351286; }
        html { scroll-behavior: smooth; overflow-x: clip; }
        html, body { overflow-x: clip; }
        body { background: #fff; }
        .public-header {
            background: #fff;
            border-bottom: 1px solid #E7E5EE;
            padding: 0 clamp(20px, 3.3vw, 51px);
            min-height: 96px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .public-header .brand {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }
        .public-header .brand img { height: 68px; width: auto; display: block; }
        .header-actions { display: flex; align-items: center; gap: 22px; }
        .back-inline {
            font-size: 14.5px;
            font-weight: 600;
            color: #3F3D56;
            text-decoration: none;
            transition: color .2s ease;
        }
        .back-inline:hover { color: var(--pub-brand); }
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

        .jd-container {
            max-width: 1040px;
            margin: 0 auto;
            padding: 1.75rem clamp(20px, 3.3vw, 51px) 3rem;
        }
        .jd-breadcrumb {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            font-size: .875rem;
            font-weight: 600;
            color: #6E7391;
            text-decoration: none;
            margin-bottom: 1rem;
            transition: color .2s ease;
        }
        .jd-breadcrumb:hover { color: var(--pub-brand); }
        .jd-breadcrumb svg { width: 14px; height: 14px; }

        .jd-preview-banner {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: .5rem .75rem;
            background: #FFF7E6;
            border: 1px solid #F0D9A4;
            color: #7A5B1E;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 1rem;
            font-size: .85rem;
            font-weight: 600;
        }
        .jd-preview-banner svg { width: 18px; height: 18px; flex-shrink: 0; }
        .jd-preview-banner .jd-preview-back {
            margin-left: auto;
            color: var(--pub-brand);
            font-weight: 600;
            text-decoration: none;
        }
        .jd-preview-banner .jd-preview-back:hover { text-decoration: underline; }
        .jd-preview .btn-apply-lg, .jd-preview .btn-apply-wide { pointer-events: none; opacity: .85; }

        .jd-card {
            background: #fff;
            border: 1px solid #ECE9F4;
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(18,16,46,.04), 0 12px 30px -26px rgba(67,16,159,.25);
        }

        .jd-hero {
            padding: clamp(22px, 3.2vw, 34px);
            background:
                radial-gradient(90% 160% at 100% 0%, rgba(229,213,250,.4) 0%, rgba(229,213,250,0) 58%),
                linear-gradient(180deg, #FBFAFE 0%, #F6F2FC 100%);
            border-bottom: 1px solid #ECE9F4;
        }
        .jd-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 28px;
        }
        .jd-badge-line { display: flex; align-items: center; gap: .5rem; margin-bottom: .6rem; }
        .jd-title {
            margin: 0 0 .55rem;
            font-size: clamp(1.5rem, 2.6vw, 2.05rem);
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #241C4F;
            line-height: 1.2;
        }
        .jd-company {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: .45rem .6rem;
            font-size: .95rem;
            color: #4B4A68;
            margin-bottom: 1rem;
        }
        .jd-company-avatar {
            width: 34px; height: 34px;
            border-radius: 9px;
            background: var(--pub-brand);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .95rem;
            flex-shrink: 0;
        }
        .jd-company-name { font-weight: 600; color: #241C4F; }
        .jd-verified {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            font-size: .72rem;
            font-weight: 600;
            color: var(--pub-brand);
            background: rgba(67,16,159,.08);
            border-radius: 999px;
            padding: 4px 10px;
            white-space: nowrap;
        }
        .jd-meta {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem 1.3rem;
            font-size: .82rem;
            color: #6E7391;
        }
        .meta-item { display: inline-flex; align-items: center; gap: .38rem; white-space: nowrap; }
        .meta-item svg { width: 15px; height: 15px; flex-shrink: 0; opacity: .85; }
        .jd-hero-cta { flex-shrink: 0; }

        .btn-apply-lg {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            height: 52px;
            padding: 0 34px;
            background: var(--pub-brand);
            color: #fff;
            font-size: 15.5px;
            font-weight: 600;
            border-radius: 10px;
            text-decoration: none;
            border: 0;
            cursor: pointer;
            box-shadow: 0 6px 18px -6px rgba(67,16,159,.5);
            transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
        }
        .btn-apply-lg svg, .btn-apply-wide svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }
        .btn-apply-lg:hover {
            background: var(--pub-brand-dark);
            transform: translateY(-2px);
            box-shadow: 0 12px 26px -8px rgba(67,16,159,.55);
        }
        .btn-apply-lg:focus-visible, .btn-apply-wide:focus-visible, .jd-breadcrumb:focus-visible {
            outline: 2px solid var(--pub-brand-dark);
            outline-offset: 3px;
        }

        .jd-layout {
            margin-top: 1.25rem;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 350px;
            gap: 1.25rem;
            align-items: start;
        }

        .jd-content { min-width: 0; }
        .jd-sec { padding: 1.4rem 1.6rem; margin-bottom: 1.1rem; }
        .jd-sec h2 {
            margin: 0 0 .85rem;
            font-size: 1.02rem;
            font-weight: 700;
            color: #241C4F;
            padding-bottom: .5rem;
            border-bottom: 1px solid #ECE9F4;
            letter-spacing: -0.01em;
        }
        .jd-sec p, .jd-sec ul { font-size: .9rem; color: #4B4A68; line-height: 1.75; }
        .jd-sec ul { padding-left: 1.25rem; }
        .jd-sec ul li { margin-bottom: .4rem; }

        .jd-side { display: flex; flex-direction: column; gap: 1.1rem; min-width: 0; }
        .jd-apply-card { padding: 1.5rem 1.6rem; }
        .jd-apply-card h2 {
            margin: 0 0 .4rem;
            font-size: 1.05rem;
            font-weight: 700;
            color: #241C4F;
        }
        .jd-apply-card .jd-apply-sub {
            margin: 0 0 1rem;
            font-size: .82rem;
            color: #6E7391;
            line-height: 1.6;
        }
        .btn-apply-wide {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            height: 50px;
            padding: 0 22px;
            background: var(--pub-brand);
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            border-radius: 10px;
            text-decoration: none;
            border: 0;
            cursor: pointer;
            box-shadow: 0 6px 18px -6px rgba(67,16,159,.5);
            transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
        }
        .btn-apply-wide:hover {
            background: var(--pub-brand-dark);
            transform: translateY(-2px);
            box-shadow: 0 12px 26px -8px rgba(67,16,159,.55);
        }
        .jd-req-title {
            margin: 1.1rem 0 0;
            padding-top: 1rem;
            border-top: 1px solid #ECE9F4;
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #6E7391;
        }
        .jd-requirements {
            margin: .6rem 0 0;
            list-style: none;
            padding-left: 0;
            display: grid;
            gap: .55rem;
        }
        .jd-requirements li { display: flex; align-items: flex-start; gap: .55rem; font-size: .84rem; color: #4B4A68; line-height: 1.5; }
        .jd-requirements svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 2px; color: var(--pub-brand); }
        .jd-closed {
            display: flex;
            gap: .65rem;
            padding: 12px 14px;
            border: 1px solid #F0D5DE;
            background: #FDF3F6;
            border-radius: 10px;
            font-size: .85rem;
            color: #A51C3C;
            line-height: 1.55;
        }
        .jd-closed strong { display: block; font-size: .9rem; }
        .jd-closed svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

        .jd-facts { padding: 1.4rem 1.6rem; }
        .jd-facts h2 {
            margin: 0 0 .9rem;
            font-size: .95rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #6E7391;
        }
        .jd-fact {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 1rem;
            padding: .5rem 0;
            font-size: .85rem;
            border-bottom: 1px solid #F1EFF7;
        }
        .jd-fact:last-child { border-bottom: 0; }
        .jd-fact dt { color: #6E7391; }
        .jd-fact dd { margin: 0; font-weight: 600; color: #241C4F; text-align: right; overflow-wrap: anywhere; }

        .jd-side-link {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            font-size: .85rem;
            font-weight: 600;
            color: var(--pub-brand);
            text-decoration: none;
            padding: .4rem 0;
        }
        .jd-side-link:hover { color: var(--pub-brand-dark); text-decoration: underline; }
        .jd-side-link svg { width: 13px; height: 13px; }

        .jd-container { animation: detailIn .5s ease both; }
        @keyframes detailIn {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (min-width: 981px) {
            .jd-side { position: sticky; top: 118px; }
        }

        @media (max-width: 860px) {
            .jd-layout { grid-template-columns: 1fr; }
            .jd-hero-cta { display: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .jd-container { animation: none; }
            * { transition-duration: .01ms !important; }
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
<?php require __DIR__ . '/../includes/maintenance_banner.php'; ?>
    <header class="public-header">
        <a href="<?= e(BASE_URL) ?>/public/browse-jobs.php" class="brand" aria-label="TRI-M Global Logistics &amp; Trading Inc. — Jobs">
            <img src="<?= e(BASE_URL) ?>/assets/images/tri-m-logo.png" alt="TRI-M GLOBAL — Logistics &amp; Trading Inc.">
        </a>
        <div class="header-actions">
            <a href="<?= e(BASE_URL) ?>/public/browse-jobs.php" class="back-inline">&larr; All Jobs</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="<?= e(BASE_URL) ?>/index.php" class="btn-login">Dashboard</a>
            <?php else: ?>
                <a href="<?= e(BASE_URL) ?>/auth/login.php" class="btn-login">Log In</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="jd-container">
        <?php if ($preview): ?>
            <div class="jd-preview-banner" role="note">
                <?= $icons['eye'] ?>
                <span>Preview — this is how applicants will see this posting once saved.</span>
                <a href="<?= e(BASE_URL) ?>/modules/recruitment/job_create.php" class="jd-preview-back">&larr; Back to form</a>
            </div>
        <?php endif; ?>

        <a href="<?= e(BASE_URL) ?>/public/browse-jobs.php" class="jd-breadcrumb"><?= $icons['chevron'] ?>Browse Jobs</a>

        <div class="jd-card">
            <div class="jd-hero">
                <div class="jd-head">
                    <div class="jd-head-text">
                        <div class="jd-badge-line"><?= statusBadge($job['status'] ?? 'closed') ?></div>
                        <h1 class="jd-title"><?= e($job['title'] ?? '') ?></h1>
                        <div class="jd-company">
                            <span class="jd-company-avatar" aria-hidden="true">T</span>
                            <span class="jd-company-name">TRI-M Global Logistics &amp; Trading Inc.</span>
                            <span class="jd-verified"><?= $icons['check'] ?>Direct Employer</span>
                        </div>
                        <div class="jd-meta">
                            <?php if (!empty($job['work_location'] ?? '')): ?>
                                <span class="meta-item"><?= $icons['pin'] ?><?= e($job['work_location']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($job['job_employment_type'] ?? '')): ?>
                                <span class="meta-item"><?= $icons['briefcase'] ?><?= e(ucfirst(str_replace('_', ' ', (string)$job['job_employment_type']))) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($job['department_name'] ?? '')): ?>
                                <span class="meta-item"><?= $icons['monitor'] ?><?= e($job['department_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty(trim((string) ($job['salary_compensation'] ?? '')))): ?>
                                <span class="meta-item"><?= $icons['money'] ?><?= e($job['salary_compensation']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($job['posted_date'] ?? '')): ?>
                                <span class="meta-item"><?= $icons['calendar'] ?>Posted <?= formatDate($job['posted_date']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isOpen): ?>
                        <div class="jd-hero-cta">
                            <a href="<?= $preview ? '#preview' : 'apply.php?id=' . (int) ($job['id'] ?? 0) ?>" class="btn-apply-lg">Apply Now <?= $icons['chevron'] ?></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="jd-layout<?= $preview ? ' jd-preview' : '' ?>">
                <main class="jd-content">
                    <?php if (!empty(trim((string) ($job['about_role'] ?? '')))): ?>
                        <section class="jd-sec jd-card">
                            <h2>About the Role</h2>
                            <p><?= nl2br(e($job['about_role'])) ?></p>
                        </section>
                    <?php endif; ?>

                    <?php if (!empty($job['qualifications'] ?? '')): ?>
                        <section class="jd-sec jd-card">
                            <h2>Qualifications</h2>
                            <p><?= nl2br(e($job['qualifications'])) ?></p>
                        </section>
                    <?php endif; ?>

                    <?php if (!empty($job['required_skills'] ?? '')): ?>
                        <section class="jd-sec jd-card">
                            <h2>Required Skills</h2>
                            <p><?= nl2br(e($job['required_skills'])) ?></p>
                        </section>
                    <?php endif; ?>

                    <?php if (!empty($job['education_requirement'] ?? '')): ?>
                        <section class="jd-sec jd-card">
                            <h2>Education</h2>
                            <p><?= nl2br(e($job['education_requirement'])) ?></p>
                        </section>
                    <?php endif; ?>

                    <?php if (!empty($job['experience_requirement'] ?? '')): ?>
                        <section class="jd-sec jd-card">
                            <h2>Experience</h2>
                            <p><?= nl2br(e($job['experience_requirement'])) ?></p>
                        </section>
                    <?php endif; ?>
                </main>

                <aside class="jd-side">
                    <div class="jd-apply-card jd-card">
                        <?php if ($isOpen): ?>
                            <h2>Apply for this job</h2>
                            <p class="jd-apply-sub">Take the next step in your career with TRI-M.</p>
                            <a href="<?= $applyHref ?>" class="btn-apply-wide">Apply Now <?= $icons['chevron'] ?></a>
                        <?php else: ?>
                            <div class="jd-closed" role="note">
                                <?= $icons['clock'] ?>
                                <div>
                                    <strong>Applications Closed</strong>
                                    This position is no longer accepting applications at this time.
                                </div>
                            </div>
                            <a href="<?= e(BASE_URL) ?>/public/browse-jobs.php" class="jd-side-link">Browse open jobs <?= $icons['chevron'] ?></a>
                        <?php endif; ?>
                        <p class="jd-req-title">Application Requirements</p>
                        <ul class="jd-requirements">
                            <li><?= $icons['check'] ?><span><strong>Resume</strong> &mdash; upload a PDF, DOC, or DOCX</span></li>
                            <li><?= $icons['check'] ?><span><strong>Cover Letter</strong> &mdash; required for every application</span></li>
                        </ul>
                    </div>

                    <div class="jd-facts jd-card">
                        <h2>Job Overview</h2>
                        <dl>
                            <?php if (!empty($job['job_employment_type'] ?? '')): ?>
                                <div class="jd-fact">
                                    <dt>Employment Type</dt>
                                    <dd><?= e(ucfirst(str_replace('_', ' ', (string)$job['job_employment_type']))) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($job['department_name'] ?? '')): ?>
                                <div class="jd-fact">
                                    <dt>Department</dt>
                                    <dd><?= e($job['department_name']) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty(trim((string) ($job['salary_compensation'] ?? '')))): ?>
                                <div class="jd-fact">
                                    <dt>Salary / Compensation</dt>
                                    <dd><?= e($job['salary_compensation']) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($job['work_location'] ?? '')): ?>
                                <div class="jd-fact">
                                    <dt>Location</dt>
                                    <dd><?= e($job['work_location']) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($job['vacancies'])): ?>
                                <div class="jd-fact">
                                    <dt>Vacancies</dt>
                                    <dd><?= (int)$job['vacancies'] ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($job['job_code'] ?? '')): ?>
                                <div class="jd-fact">
                                    <dt>Job Code</dt>
                                    <dd><?= e($job['job_code']) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($job['posted_date'] ?? '')): ?>
                                <div class="jd-fact">
                                    <dt>Posted</dt>
                                    <dd><?= formatDate($job['posted_date']) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($job['closing_date'] ?? '')): ?>
                                <div class="jd-fact">
                                    <dt>Application Deadline</dt>
                                    <dd><?= formatDate($job['closing_date']) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    </div>

                    <a href="<?= e(BASE_URL) ?>/public/browse-jobs.php" class="jd-side-link"><?= $icons['chevron'] ?>View all job openings</a>
                </aside>
            </div>
        </div>
    </div>

    <footer class="public-footer">
        &copy; <?= date('Y') ?> TRI-M Global Logistics &amp; Trading Inc. All rights reserved.
    </footer>
</body>
</html>