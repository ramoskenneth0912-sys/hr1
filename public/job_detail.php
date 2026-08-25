<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security_headers.php';

if (isLoggedIn() && isEmployee()) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($job['title']) ?> - <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <style>
        :root { --pub-brand: #43109F; --pub-brand-dark: #36088C; --pub-footer: #351286; }
        html { scroll-behavior: smooth; }
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
        .detail-container {
            max-width: 860px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            font-size: .875rem;
            font-weight: 600;
            color: var(--purple);
            text-decoration: none;
            margin-bottom: 1.5rem;
        }
        .back-link:hover { color: var(--purple-dark); }
        .detail-title-section {
            margin-bottom: 1.5rem;
        }
        .detail-title-section h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: .5rem;
            letter-spacing: -0.02em;
        }
        .detail-title-section .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: .875rem;
            color: var(--muted);
        }
        .detail-section {
            margin-bottom: 1.5rem;
        }
        .detail-section h2 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: .75rem;
            padding-bottom: .5rem;
            border-bottom: 1px solid var(--border);
        }
        .detail-section p,
        .detail-section ul {
            font-size: .9rem;
            color: var(--text);
            line-height: 1.7;
        }
        .detail-section ul {
            padding-left: 1.25rem;
        }
        .detail-section ul li {
            margin-bottom: .35rem;
        }
        .detail-actions {
            margin-top: 2rem;
            display: flex;
            gap: .75rem;
        }
        .detail-actions .btn { transition: transform .2s ease, box-shadow .2s ease, background .2s ease; }
        .detail-actions .btn:hover { transform: translateY(-1px); }
        .detail-container { animation: detailIn .55s ease both; }
        @keyframes detailIn {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .detail-container { animation: none; }
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
    <header class="public-header">
        <a href="jobs.php" class="brand" aria-label="TRI-M Global Logistics &amp; Trading Inc. — Jobs">
            <img src="../assets/images/tri-m-logo.png" alt="TRI-M GLOBAL — Logistics &amp; Trading Inc.">
        </a>
        <div class="header-actions">
            <a href="jobs.php" class="back-inline">&larr; Back to Jobs</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="<?= BASE_URL ?>/index.php" class="btn-login">Dashboard</a>
            <?php else: ?>
                <a href="../auth/login.php" class="btn-login">Log In</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="detail-container">
        <a href="jobs.php" class="back-link">&larr; Back to Jobs</a>

        <div class="detail-title-section">
            <h1><?= e($job['title']) ?></h1>
            <div class="meta">
                <span>&#128188; <?= e($job['department_name'] ?? 'N/A') ?></span>
                <span>&#128187; <?= e($job['job_employment_type'] ?? 'N/A') ?></span>
                <span>&#128205; <?= e($job['work_location'] ?? 'N/A') ?></span>
                <?= statusBadge($job['status']) ?>
            </div>
        </div>

        <div class="panel">
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Vacancies</label>
                    <span><?= $job['vacancies'] ? (int)$job['vacancies'] : '—' ?></span>
                </div>
                <div class="detail-item">
                    <label>Job Code</label>
                    <span><?= e($job['job_code'] ?? '—') ?></span>
                </div>
                <div class="detail-item">
                    <label>Posted Date</label>
                    <span><?= formatDate($job['posted_date']) ?></span>
                </div>
                <div class="detail-item">
                    <label>Closing Date</label>
                    <span><?= formatDate($job['closing_date']) ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($job['description'])): ?>
            <div class="panel detail-section">
                <h2>Job Description</h2>
                <p><?= nl2br(e($job['description'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($job['requirements'])): ?>
            <div class="panel detail-section">
                <h2>Requirements</h2>
                <p><?= nl2br(e($job['requirements'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($job['qualifications'])): ?>
            <div class="panel detail-section">
                <h2>Qualifications</h2>
                <p><?= nl2br(e($job['qualifications'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($job['required_skills'])): ?>
            <div class="panel detail-section">
                <h2>Required Skills</h2>
                <p><?= nl2br(e($job['required_skills'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($job['education_requirement'])): ?>
            <div class="panel detail-section">
                <h2>Education Requirement</h2>
                <p><?= nl2br(e($job['education_requirement'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($job['experience_requirement'])): ?>
            <div class="panel detail-section">
                <h2>Experience Requirement</h2>
                <p><?= nl2br(e($job['experience_requirement'])) ?></p>
            </div>
        <?php endif; ?>

        <div class="detail-actions">
            <a href="apply.php?id=<?= (int)$job['id'] ?>" class="btn btn-primary">Apply Now</a>
            <a href="jobs.php" class="btn btn-outline">&larr; Back to Jobs</a>
        </div>
    </div>

    <footer class="public-footer">
        &copy; <?= date('Y') ?> TRI-M Global Logistics &amp; Trading Inc. All rights reserved.
    </footer>
</body>
</html>
