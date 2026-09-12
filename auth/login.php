<?php
require_once __DIR__ . '/../includes/session.php';
if (!defined('MAINTENANCE_EXEMPT_PAGE')) {
    define('MAINTENANCE_EXEMPT_PAGE', true);
}
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/security_log.php';

function e_login(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'] ?? 'employee';
    if ($role === 'applicant') {
        header('Location: ' . BASE_URL . '/modules/applicant/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/index.php');
    }
    exit;
}

$error = '';
$loginKey = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if (!webRateLimit($loginKey)) {
        $error = 'Too many failed login attempts. Please try again in 15 minutes.';
    } else {
        $credential = trim($_POST['credential'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($credential === '' || $password === '') {
            $error = 'Please enter both username/email and password.';
        } else {
            try {
                $stmt = db()->prepare('SELECT id, username, email, role, password_hash FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1');
                $stmt->execute([$credential, $credential]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    webRateLimitReset($loginKey);
                    error_log('HR1 LOGIN OK: ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' user_id=' . $user['id'] . ' role=' . $user['role'] . ' time=' . date('c'));
                    securityLog('login_success', "role={$user['role']}", (int) $user['id']);

                    session_regenerate_id(true);
                    csrf_rotate();
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['username'];
                    $_SESSION['pw_changed'] = $user['password_changed_at'] ?? '';

                    if (in_array($user['role'], ['hr', 'manager'], true)) {
                        header('Location: ' . BASE_URL . '/index.php');
                    } elseif ($user['role'] === 'applicant') {
                        header('Location: ' . BASE_URL . '/modules/applicant/dashboard.php');
                    } else {
                        header('Location: ' . BASE_URL . '/index.php');
                    }
                    exit;
                }

                webRateLimitRecord($loginKey);
                error_log('HR1 LOGIN FAIL: ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' user=' . $credential . ' time=' . date('c'));
                securityLog('login_fail', "credential=" . substr($credential, 0, 50));
                $error = 'Invalid username/email or password.';
            } catch (PDOException $ex) {
                $error = 'Login system unavailable. Please try again later.';
            }
        }
    }
}

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In Ã¢â‚¬â€ <?= e_login(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tailwind.css">
    <style>
        * { box-sizing: border-box; }
        body.login-page {
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
        .bg-circle { position: fixed; border-radius: 50%; pointer-events: none; }
        .bg-circle.c1 {
            width: 300px; height: 300px;
            top: -110px; left: -90px;
            background: rgba(255,255,255,.05);
        }
        .bg-circle.c2 {
            width: 380px; height: 380px;
            bottom: -160px; right: -120px;
            border: 1.5px solid rgba(255,255,255,.13);
        }
        .bg-ring.r1 {
            position: fixed;
            width: 180px; height: 180px;
            border-radius: 50%;
            border: 1.5px solid rgba(255,255,255,.11);
            top: 16%; right: 9%;
            pointer-events: none;
        }

        /* ---------- OUTER CARD ---------- */
        .auth-shell {
            position: relative;
            z-index: 1;
            width: min(1060px, 100%);
            display: flex;
            background: #FFFFFF;
            border-radius: 26px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.28);
            box-shadow: 0 44px 96px -34px rgba(9,3,38,.6);
        }

        /* ---------- LEFT BRAND PANEL ---------- */
        .panel-brand {
            position: relative;
            flex: 0 0 45%;
            min-width: 0;
            display: flex;
            padding: clamp(2rem, 3.6vw, 3.4rem);
            overflow: hidden;
            color: #fff;
            background:
                radial-gradient(700px 480px at 92% -12%, rgba(157,78,221,.30), rgba(157,78,221,0) 60%),
                radial-gradient(520px 420px at -12% 112%, rgba(24,7,66,.5), rgba(24,7,66,0) 65%),
                linear-gradient(150deg, #250850 0%, #3B0B86 48%, #6210AC 100%);
        }
        .brand-shape { position: absolute; border-radius: 50%; pointer-events: none; }
        .brand-shape.s1 {
            width: 260px; height: 260px;
            top: -100px; right: -70px;
            border: 1.5px solid rgba(255,255,255,.14);
        }
        .brand-shape.s2 {
            width: 160px; height: 160px;
            top: -50px; right: 120px;
            background: rgba(255,255,255,.05);
        }
        .brand-shape.s3 {
            width: 320px; height: 320px;
            bottom: -150px; left: -110px;
            border: 1.5px solid rgba(255,255,255,.11);
        }
        .brand-shape.s4 {
            width: 200px; height: 200px;
            bottom: -60px; left: 140px;
            background: rgba(157,78,221,.17);
            filter: blur(2px);
        }
        .brand-line {
            position: absolute;
            width: 420px; height: 1.5px;
            background: linear-gradient(90deg, rgba(255,255,255,0), rgba(255,255,255,.17), rgba(255,255,255,0));
            transform: rotate(-24deg);
            top: 32%; right: -120px;
            pointer-events: none;
        }
        .brand-content {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .brand-copy h1 {
            font-size: clamp(1.65rem, 2.1vw, 2.1rem);
            font-weight: 700;
            letter-spacing: -.02em;
            margin-bottom: .8rem;
        }
        .brand-copy .lead {
            font-size: clamp(.92rem, 1.05vw, 1.02rem);
            font-weight: 500;
            color: rgba(255,255,255,.94);
            max-width: 40ch;
            margin-bottom: .9rem;
        }
        .brand-copy .desc {
            font-size: .86rem;
            line-height: 1.7;
            color: rgba(255,255,255,.68);
            max-width: 42ch;
        }
        .brand-tag {
            margin-top: auto;
            padding-top: 2.25rem;
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            font-size: .75rem;
            letter-spacing: .04em;
            color: rgba(255,255,255,.55);
        }
        .brand-tag::before {
            content: '';
            width: 26px; height: 1.5px;
            background: rgba(255,255,255,.35);
            border-radius: 2px;
        }

        /* ---------- RIGHT LOGIN PANEL ---------- */
        .panel-form {
            flex: 1;
            min-width: 0;
            background: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(2rem, 3.6vw, 3.25rem);
        }
        .form-side {
            width: 100%;
            max-width: 396px;
        }
        .login-logo {
            display: block;
            width: clamp(160px, 18vw, 198px);
            height: auto;
            margin: 0 auto 1.35rem;
        }
        .form-side h2 {
            font-size: 1.42rem;
            font-weight: 700;
            letter-spacing: -.01em;
            color: var(--text-dark);
            text-align: center;
            margin-bottom: .8rem;
        }
        .portal-badge {
            display: table;
            margin: 0 auto 1.35rem;
            padding: .42rem .9rem;
            border-radius: 999px;
            background: var(--purple-bg);
            color: var(--text-dark);
            font-size: .73rem;
            font-weight: 600;
            letter-spacing: .02em;
            white-space: nowrap;
        }

        /* ---------- INNER LOGIN BOX ---------- */
        .login-inner {
            background: #F9F7FE;
            border: 1px solid #ECE9F4;
            border-radius: 16px;
            padding: 1.5rem 1.4rem 1.4rem;
            box-shadow: 0 12px 30px -22px rgba(43,22,110,.35);
        }
        .login-alert { margin-bottom: 1.1rem; }
        .login-inner .form-group { margin-bottom: 1rem; }
        .login-inner .form-group label {
            display: block;
            font-size: .78rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: .4rem;
        }
        .input-wrap { position: relative; }
        .login-inner input[type="text"],
        .login-inner input[type="password"] {
            width: 100%;
            padding: .74rem .9rem;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: .875rem;
            font-family: inherit;
            color: var(--text);
            background: #FFFFFF;
            transition: border-color .15s, box-shadow .15s;
        }
        .login-inner .has-trailing { padding-right: 2.7rem; }
        .login-inner input:focus {
            outline: none;
            border-color: var(--purple-light);
            box-shadow: 0 0 0 3px var(--purple-bg);
        }
        .pw-toggle {
            position: absolute;
            right: .55rem;
            top: 50%;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px; height: 30px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
            transition: color .15s ease, background .15s ease;
        }
        .pw-toggle:hover { color: var(--text-dark); background: var(--purple-bg); }
        .pw-toggle svg { width: 16px; height: 16px; }
        .pw-toggle .icon-eye-off { display: none; }
        .pw-toggle.active .icon-eye { display: none; }
        .pw-toggle.active .icon-eye-off { display: block; }
        .pw-toggle:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; }
        .login-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            margin: .15rem 0 1.1rem;
        }
        .remember-me {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            font-size: .78rem;
            font-weight: 500;
            color: var(--text);
            cursor: pointer;
            user-select: none;
        }
        .remember-me input {
            width: 15px; height: 15px;
            margin: 0;
            accent-color: var(--purple);
            cursor: pointer;
        }
        .login-forgot {
            font-size: .78rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        .login-forgot:hover { color: var(--text-dark); }
        .login-inner .btn-primary {
            width: 100%;
            padding: .78rem;
            font-size: .92rem;
            font-weight: 600;
            letter-spacing: .03em;
            border-radius: 10px;
            box-shadow: 0 10px 22px -10px rgba(106,13,173,.55);
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
        }
        .login-inner .btn-primary:hover {
            transform: translateY(-1px);
            background: linear-gradient(135deg, var(--purple-dark), var(--purple)) !important;
            box-shadow: 0 14px 28px -10px rgba(106,13,173,.62);
        }
        .login-inner .btn-primary:focus-visible {
            outline: 2px solid var(--purple-dark);
            outline-offset: 3px;
        }
        .login-footnote {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            margin-top: 1.25rem;
            font-size: .72rem;
            color: var(--muted);
            text-align: center;
        }
        .login-footnote svg { width: 12px; height: 12px; flex-shrink: 0; opacity: .8; }
        .login-back {
            display: block;
            text-align: center;
            margin-top: 1.1rem;
            font-size: .8rem;
            font-weight: 500;
            color: var(--muted);
        }
        .login-back:hover { color: var(--text-dark); }

        /* ---------- RESPONSIVE ---------- */
        @media (max-width: 980px) {
            body.login-page { align-items: flex-start; }
            .auth-shell { flex-direction: column; width: min(560px, 100%); }
            .panel-brand { flex: none; padding: 1.75rem 1.5rem; }
            .brand-copy h1 { font-size: 1.4rem; margin-bottom: .45rem; }
            .brand-copy .lead { font-size: .86rem; margin-bottom: .4rem; }
            .brand-copy .desc { display: none; }
            .brand-tag { display: none; }
            .brand-shape.s3, .brand-shape.s4, .brand-line { display: none; }
            .panel-form { padding: 1.9rem 1.4rem 2.3rem; }
        }
        @media (max-width: 480px) {
            .panel-form { padding: 1.6rem 1.1rem 2rem; }
            .login-inner { padding: 1.2rem 1rem 1.15rem; }
            .login-row { flex-wrap: wrap; row-gap: .55rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            .login-inner .btn-primary, .pw-toggle { transition: none; }
        }
    </style>
</head>
<body class="login-page">

<span class="bg-circle c1" aria-hidden="true"></span>
<span class="bg-circle c2" aria-hidden="true"></span>
<span class="bg-ring r1" aria-hidden="true"></span>

<div class="auth-shell">
    <section class="panel-brand">
        <span class="brand-shape s1" aria-hidden="true"></span>
        <span class="brand-shape s2" aria-hidden="true"></span>
        <span class="brand-shape s3" aria-hidden="true"></span>
        <span class="brand-shape s4" aria-hidden="true"></span>
        <span class="brand-line" aria-hidden="true"></span>
        <div class="brand-content">
            <div class="brand-copy">
                <h1>Welcome Back</h1>
                <p class="lead">Access the TRI-M GLOBAL Merchandising Management System.</p>
                <p class="desc">Manage employee records, recruitment, and HR operations through a secure and centralized system.</p>
            </div>
            <span class="brand-tag">Building connections. Delivering excellence.</span>
        </div>
    </section>

    <main class="panel-form">
        <div class="form-side">
            <img src="<?= BASE_URL ?>/assets/images/login%20logo.jpg" alt="TRI-M GLOBAL LOGISTICS &amp; TRADING INC." class="login-logo">
            <h2>Sign in to your account</h2>
           

            <?php if ($flash): ?>
                <div class="alert alert-<?= e_login($flash['type']) ?> login-alert"><?= e_login($flash['message']) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger login-alert"><?= e_login($error) ?></div>
            <?php endif; ?>

            <div class="login-inner">
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="credential">Email Address</label>
                        <div class="input-wrap">
                            <input type="text" id="credential" name="credential" value="<?= e_login($_POST['credential'] ?? '') ?>" required autofocus placeholder="Enter your email" autocomplete="username">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <input type="password" id="password" name="password" required placeholder="Enter your password" autocomplete="current-password" class="has-trailing">
                            <button type="button" id="togglePassword" class="pw-toggle" aria-label="Show password" aria-pressed="false">
                                <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="login-row">
                        <label class="remember-me">
                            <input type="checkbox" name="remember_me" value="1">
                            Remember me
                        </label>
                        <a href="<?= BASE_URL ?>/auth/forgot_password.php" class="login-forgot">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary">Sign In</button>
                </form>
            </div>

            <p class="login-footnote">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Authorized access for HR Managers and Employees.
            </p>

            <a href="<?= BASE_URL ?>/public/jobs.php" class="login-back">&larr; Back to Dashboard</a>
        </div>
    </main>
</div>

<script>
(function () {
    var toggle = document.getElementById('togglePassword');
    var pw = document.getElementById('password');
    if (!toggle || !pw) return;
    toggle.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        toggle.classList.toggle('active', show);
        toggle.setAttribute('aria-pressed', String(show));
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
})();
</script>

</body>
</html>
