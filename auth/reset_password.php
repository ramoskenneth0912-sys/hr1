<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/password_reset.php';

function e_rp(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$rawToken = $_GET['token'] ?? '';
$error = '';
$success = false;
$tokenValid = false;
$resetEmail = '';

if ($rawToken !== '' && strlen($rawToken) === 64 && ctype_xdigit($rawToken)) {
    $resetEmail = passwordResetValidateToken($rawToken);
    if ($resetEmail) {
        $tokenValid = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $postToken = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirmation'] ?? '';

    if ($postToken === '' || strlen($postToken) !== 64 || !ctype_xdigit($postToken)) {
        $error = 'Invalid or expired reset link.';
    } elseif ($password === '') {
        $error = 'Please enter a new password.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $email = passwordResetConsumeToken($postToken);
        if (!$email) {
            $error = 'This reset link has expired or already been used. Please request a new one.';
        } else {
            if (passwordResetApply($email, $password)) {
                $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                if ($user) {
                    passwordResetInvalidateSessions((int) $user['id']);
                }

                // Destroy any existing session to prevent stale credentials
                // (e.g. HR session on a shared browser, or stale employee session).
                // The employee MUST log in fresh with the new password.
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $p['path'], $p['domain'], $p['secure'], $p['httponly']
                    );
                }
                session_destroy();
                // Start a fresh session for the success page only
                session_start();
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Your password has been reset. Please sign in with your new password.'];
                header('Location: ' . BASE_URL . '/auth/login.php');
                exit;
            } else {
                $error = 'Unable to reset password. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — <?= e_rp(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tailwind.css">
    <style>
        * { box-sizing: border-box; }
        body.reset-page {
            min-height: 100vh; margin: 0;
            display: flex; align-items: center; justify-content: center;
            padding: clamp(1.25rem, 3.5vh, 3rem) clamp(1rem, 3vw, 2.5rem);
            font-family: 'Inter', sans-serif; overflow-x: hidden;
            background:
                radial-gradient(900px 620px at 85% -8%, rgba(157,78,221,.30), rgba(157,78,221,0) 62%),
                radial-gradient(760px 560px at -6% 108%, rgba(20,6,54,.55), rgba(20,6,54,0) 64%),
                linear-gradient(128deg, #22074E 0%, #38097F 44%, #5A0FA6 76%, #7B2CBF 100%);
        }
        .auth-shell {
            position: relative; z-index: 1; width: min(560px, 100%);
            display: flex; flex-direction: column; background: #FFFFFF;
            border-radius: 26px; overflow: hidden;
            border: 1px solid rgba(255,255,255,.28);
            box-shadow: 0 44px 96px -34px rgba(9,3,38,.6);
        }
        .panel-form { padding: clamp(2rem, 3.6vw, 3.25rem); text-align: center; }
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
        .login-inner input[type="password"] {
            width: 100%; padding: .74rem .9rem;
            border: 1px solid var(--border); border-radius: 10px;
            font-size: .875rem; font-family: inherit; color: var(--text);
            background: #FFFFFF; transition: border-color .15s, box-shadow .15s;
        }
        .login-inner input[type="password"]:focus {
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
            font-size: .8rem; font-weight: 500; color: var(--muted); text-decoration: none;
        }
        .login-back:hover { color: var(--purple); }
        .success-msg {
            background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;
            border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1rem;
            font-size: .875rem; line-height: 1.5; text-align: left;
        }
        .expired-msg {
            background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1rem;
            font-size: .875rem; line-height: 1.5; text-align: left;
        }
        @media (prefers-reduced-motion: reduce) {
            .login-inner .btn-primary { transition: none; }
        }
    </style>
</head>
<body class="reset-page">

<div class="auth-shell">
    <main class="panel-form">
        <div class="form-side">
            <img src="<?= BASE_URL ?>/assets/images/login%20logo.jpg" alt="TRI-M GLOBAL LOGISTICS &amp; TRADING INC." class="login-logo">

            <?php if ($success): ?>
                <h2>Password reset successful</h2>
                <div class="success-msg">
                    Your password has been reset. You can now sign in with your new password.
                </div>
                <a href="<?= BASE_URL ?>/auth/login.php" class="login-back" style="margin-top:1rem;font-weight:600;color:var(--purple)">&larr; Sign In</a>

            <?php elseif (!$tokenValid && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                <h2>Invalid or expired link</h2>
                <div class="expired-msg">
                    This password reset link is invalid or has expired. Please request a new one.
                </div>
                <a href="<?= BASE_URL ?>/auth/forgot_password.php" class="login-back" style="margin-top:1rem;font-weight:600;color:var(--purple)">Request a new reset link &rarr;</a>

            <?php else: ?>
                <h2>Set new password</h2>
                <p class="subtitle">Choose a strong password for your account.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger login-alert" style="text-align:left"><?= e_rp($error) ?></div>
                <?php endif; ?>

                <div class="login-inner">
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e_rp($rawToken ?: ($_POST['token'] ?? '')) ?>">
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <input type="password" id="password" name="password" required minlength="8" placeholder="Minimum 8 characters" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label for="password_confirmation">Confirm Password</label>
                            <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8" placeholder="Re-enter password" autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn btn-primary">Reset Password</button>
                    </form>
                </div>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>/auth/login.php" class="login-back">&larr; Back to Sign In</a>
        </div>
    </main>
</div>

</body>
</html>
