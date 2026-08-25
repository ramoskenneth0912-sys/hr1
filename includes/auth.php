<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function getCurrentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT u.*, e.first_name, e.last_name, e.employee_no, e.job_title,
                e.department_id, d.name AS department_name
         FROM users u
         LEFT JOIN employees e ON u.employee_id = e.id
         LEFT JOIN departments d ON e.department_id = d.id
         WHERE u.id = ?'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    // Stale-session detection: if the password was changed after this session
    // was created, force logout.  This catches cases where a password reset or
    // admin password change happened while the user was still logged in.
    $sessionPwChanged = $_SESSION['pw_changed'] ?? '';
    $dbPwChanged      = $user['password_changed_at'] ?? '';
    if ($sessionPwChanged !== '' && $dbPwChanged !== '' && $sessionPwChanged !== $dbPwChanged) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();
        return null;
    }

    return $user;
}

function getUserRole(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

function isHR(): bool
{
    return getUserRole() === 'hr';
}

function isManager(): bool
{
    return getUserRole() === 'manager';
}

function isEmployee(): bool
{
    return getUserRole() === 'employee';
}

function isHRorManager(): bool
{
    return in_array(getUserRole(), ['hr', 'manager']);
}

function isApplicant(): bool
{
    return getUserRole() === 'applicant';
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        flash('danger', 'Please log in to access this page.');
        redirect(BASE_URL . '/auth/login.php');
    }
}

function requireHRorManager(): void
{
    requireLogin();
    if (!isHRorManager()) {
        flash('danger', 'You do not have permission to access this page.');
        redirect(BASE_URL . '/public/jobs.php');
    }
}

function requireNotApplicant(): void
{
    requireLogin();
    if (isApplicant()) {
        flash('danger', 'You do not have permission to access this page.');
        redirect(BASE_URL . '/modules/applicant/dashboard.php');
    }
}

function requireApplicant(): void
{
    requireLogin();
    if (!isApplicant()) {
        flash('danger', 'You do not have permission to access this page.');
        redirect(BASE_URL . '/public/jobs.php');
    }
}

function loginUser(string $username, string $password): bool
{
    $stmt = db()->prepare(
        'SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1'
    );
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        csrf_rotate();
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['username'];
        $_SESSION['pw_changed'] = $user['password_changed_at'] ?? '';
        return true;
    }
    return false;
}

function logoutUser(): void
{
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
    session_destroy();
    header('Location: ' . BASE_URL . '/public/jobs.php');
    exit;
}

function essOwnEmployeeId(): int {
    $user = getCurrentUser();
    return (int) ($user['employee_id'] ?? 0);
}

function essAssertOwnOrManager(int $targetId): void {
    if (isHRorManager()) return;
    if ($targetId === essOwnEmployeeId()) return;
    flash('danger', 'You can only access your own records.');
    redirect(BASE_URL . '/modules/ess/index.php');
}
