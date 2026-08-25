<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security_headers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Submitted - <?= e(APP_NAME) ?></title>
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
        .thankyou-container {
            max-width: 600px;
            margin: 4rem auto;
            padding: 0 1.5rem;
            text-align: center;
        }
        .thankyou-icon {
            width: 80px;
            height: 80px;
            background: rgba(5, 205, 153, 0.12);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.25rem;
        }
        .thankyou-container h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1rem;
        }
        .thankyou-container p {
            font-size: .95rem;
            color: var(--text);
            line-height: 1.7;
            margin-bottom: .5rem;
        }
        .thankyou-container .sub {
            font-size: .875rem;
            color: var(--muted);
            margin-bottom: 2rem;
        }
        .public-footer {
            background: var(--pub-footer);
            color: rgba(255,255,255,.75);
            text-align: center;
            padding: 22px 16px;
            font-size: 12.5px;
            margin-top: 2rem;
        }
        .thankyou-container { animation: tyIn .55s ease both; }
        @keyframes tyIn {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .thankyou-container { animation: none; }
            * { transition-duration: .01ms !important; }
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

    <div class="thankyou-container">
        <div class="thankyou-icon">&#10003;</div>
        <h1>Application Submitted Successfully!</h1>
        <div class="panel">
            <p>Thank you for your application. Our HR team will review your submission and contact you if you are shortlisted.</p>
            <p class="sub">You may check back for other open positions in the meantime.</p>
            <div style="margin-top:1.25rem;">
                <a href="jobs.php" class="btn btn-primary">&larr; Back to Jobs</a>
            </div>
        </div>
    </div>

    <footer class="public-footer">
        &copy; <?= date('Y') ?> TRI-M Global Logistics &amp; Trading Inc. All rights reserved.
    </footer>
</body>
</html>
