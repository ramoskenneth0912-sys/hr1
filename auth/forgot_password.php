<?php
require_once __DIR__ . '/../includes/session.php';
if (!defined('MAINTENANCE_EXEMPT_PAGE')) {
    define('MAINTENANCE_EXEMPT_PAGE', true);
}
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/password_reset.php';
// mail.php is not required here Ã¢â‚¬â€ HR approval page handles sending the reset link

function e_fp(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'] ?? 'employee';
    if (in_array($role, ['hr', 'manager'], true)) {
        header('Location: ' . BASE_URL . '/modules/users/index.php');
    } elseif ($role === 'applicant') {
        header('Location: ' . BASE_URL . '/modules/applicant/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/index.php');
    }
    exit;
}

$submitted = false;
$error = '';
$rateKey = 'pwreset_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if (!webRateLimit($rateKey, 3, 900)) {
        $error = 'Too many requests. Please try again in 15 minutes.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $resultMessage = passwordResetRequest($email, $_SERVER['REMOTE_ADDR'] ?? null);
            webRateLimitRecord($rateKey);
            $submitted = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Password Reset Ã¢â‚¬â€ <?= e_fp(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tailwind.css">
    <style>
        * { box-sizing: border-box; }
        body.forgot-page {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(1.25rem, 3.5vh, 3rem) clamp(1rem, 3vw, 2.5rem);
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
            background:
                radial-gradient(900px 620px at 85% -8%, rgba(157,78,221,.30), rgba(157,78,221,0) 62%),
                radial-gradient(760px 560px at -6% 108%, rgba(20,6,54,.55), rgba(20,6,54,0) 64%),
                linear-gradient(128deg, #22074E 0%, #38097F 44%, #5A0FA6 76%, #7B2CBF 100%);
        }
        .auth-shell {
            position: relative; z-index: 1;
            width: min(560px, 100%);
            display: flex; flex-direction: column;
            background: #FFFFFF;
            border-radius: 26px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.28);
            box-shadow: 0 44px 96px -34px rgba(9,3,38,.6);
        }
        .panel-form {
            padding: clamp(2rem, 3.6vw, 3.25rem);
            text-align: center;
        }
        .form-side { width: 100%; max-width: 396px; margin: 0 auto; }
        .login-logo {
            display: block; width: clamp(160px, 18vw, 198px);
            height: auto; margin: 0 auto 1.35rem;
        }
        .form-side h2 {
            font-size: 1.42rem; font-weight: 700; letter-spacing: -.01em;
            color: var(--text-dark); text-align: center; margin-bottom: .4rem;
        }
        .form-side .subtitle {
            font-size: .875rem; color: var(--muted); text-align: center;
            margin-bottom: 1.5rem; line-height: 1.5;
        }
        .login-inner {
            background: #F9F7FE; border: 1px solid #ECE9F4;
            border-radius: 16px; padding: 1.5rem 1.4rem 1.4rem;
            box-shadow: 0 12px 30px -22px rgba(43,22,110,.35);
        }
        .login-inner .form-group { margin-bottom: 1rem; text-align: left; }
        .login-inner .form-group label {
            display: block; font-size: .78rem; font-weight: 600;
            color: var(--text-dark); margin-bottom: .4rem;
        }
        .login-inner input[type="email"] {
            width: 100%; padding: .74rem .9rem;
            border: 1px solid var(--border); border-radius: 10px;
            font-size: .875rem; font-family: inherit; color: var(--text);
            background: #FFFFFF; transition: border-color .15s, box-shadow .15s;
        }
        .login-inner input[type="email"]:focus {
            outline: none; border-color: var(--purple-light);
            box-shadow: 0 0 0 3px var(--purple-bg);
        }
        .login-inner .btn-primary {
            width: 100%; padding: .78rem; font-size: .92rem;
            font-weight: 600; letter-spacing: .03em; border-radius: 10px;
            box-shadow: 0 10px 22px -10px rgba(106,13,173,.55);
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
        }
        .login-inner .btn-primary:hover {
            transform: translateY(-1px);
            background: linear-gradient(135deg, var(--purple-dark), var(--purple)) !important;
            box-shadow: 0 14px 28px -10px rgba(106,13,173,.62);
        }
        .login-inner .btn-primary:focus-visible {
            outline: 2px solid var(--purple-dark); outline-offset: 3px;
        }
        .login-back {
            display: block; text-align: center; margin-top: 1.1rem;
            font-size: .8rem; font-weight: 500; color: var(--muted);
            text-decoration: none;
        }
        .login-back:hover { color: var(--purple); }
        .success-msg {
            background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;
            border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1rem;
            font-size: .875rem; line-height: 1.5; text-align: left;
        }
        @media (prefers-reduced-motion: reduce) {
            .login-inner .btn-primary { transition: none; }
        }
    </style>
</head>
<body class="forgot-page">

<div class="auth-shell">
    <main class="panel-form">
        <div class="form-side">
            <img src="<?= BASE_URL ?>/assets/images/login%20logo.jpg" alt="TRI-M GLOBAL LOGISTICS &amp; TRADING INC." class="login-logo">
            <h2>Request password reset</h2>
            <p class="subtitle">Enter your email address. Your request will be sent to HR for approval before a reset link is issued.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger login-alert" style="text-align:left"><?= e_fp($error) ?></div>
            <?php endif; ?>

            <?php if ($submitted): ?>
                <div class="success-msg">
                    If the account exists, a password reset request has been sent to HR for approval. Once approved, you will receive an email with a link to set your new password.
                </div>
            <?php else: ?>
                <div class="login-inner">
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required autofocus placeholder="Enter your email" autocomplete="email" value="<?= e_fp($_POST['email'] ?? '') ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                    </form>
                </div>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>/auth/login.php" class="login-back">&larr; Back to Sign In</a>
        </div>
    </main>
</div>

</body>
</html>
