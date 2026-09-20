<?php
/**
 * Password reset — HR-approved workflow.
 *
 * 1. Employee requests reset → PENDING row in password_reset_requests
 * 2. HR reviews → APPROVED/REJECTED
 * 3. On approval → token written to password_resets → email sent to employee
 * 4. Employee uses token to set new password
 *
 * Tokens are 256-bit random, stored as SHA-256 hashes.
 * Lifetime: 1 hour after approval. Requests expire after 24 hours.
 * Account enumeration is prevented: all paths return the same generic response.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security_log.php';

define('PASSWORD_RESET_TOKEN_TTL', 3600);
define('PASSWORD_RESET_REQUEST_TTL', 86400);
define('PASSWORD_RESET_REQUEST_TTL_DISPLAY', '24 hours');

// ────────────────────────────────────────────────────────────
// PUBLIC API — called from auth pages
// ────────────────────────────────────────────────────────────

/**
 * Create a pending password reset request.
 * Returns a generic success message regardless of whether the email exists (prevents enumeration).
 * Logs the request to security_log.
 */
function passwordResetRequest(string $email, ?string $ip = null): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'If an account exists with that email, an approval request has been sent to HR.';
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1 AND is_archived = 0');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return 'If an account exists with that email, an approval request has been sent to HR.';
    }

    $userId = (int) $user['id'];

    db()->prepare('DELETE FROM password_reset_requests WHERE user_id = ? AND status = ?')
        ->execute([$userId, 'pending']);

    $publicToken = bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', time() + PASSWORD_RESET_REQUEST_TTL);
    $maskedEmail = maskEmailForDisplay($email);

    db()->prepare(
        'INSERT INTO password_reset_requests (user_id, masked_email, public_token, requested_by_ip, expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    )->execute([$userId, $maskedEmail, $publicToken, $ip, $expiresAt]);

    securityLog('password_reset_request', "user_id={$userId}", $userId);

    notifyHRofResetRequest($userId, $maskedEmail);

    return 'If an account exists with that email, an approval request has been sent to HR.';
}

/**
 * Get pending (non-expired) requests for the HR approval page.
 */
function getPendingResetRequests(): array
{
    expireOldRequests();
    try {
        $stmt = db()->prepare(
            'SELECT prr.*, u.username, u.email AS user_email
             FROM password_reset_requests prr
             JOIN users u ON u.id = prr.user_id
             WHERE prr.status = ? AND prr.expires_at > NOW()
             ORDER BY prr.created_at DESC'
        );
        $stmt->execute(['pending']);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('getPendingResetRequests failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get all requests (any status) for the HR history view.
 */
function getAllResetRequests(int $limit = 100): array
{
    expireOldRequests();
    try {
        $stmt = db()->prepare(
            'SELECT prr.*, u.username, u.email AS user_email,
                    a.username AS action_by_name
             FROM password_reset_requests prr
             JOIN users u ON u.id = prr.user_id
             LEFT JOIN users a ON a.id = prr.action_by
             ORDER BY prr.created_at DESC
             LIMIT ?'
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('getAllResetRequests failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Approve a reset request: create token, record approval, return raw token for email.
 * Returns null if the request cannot be approved.
 * Prevents self-approval.
 */
function approveResetRequest(int $requestId, int $hrUserId, ?string $ip = null): ?string
{
    $stmt = db()->prepare(
        'SELECT prr.*, u.email AS user_email
         FROM password_reset_requests prr
         JOIN users u ON u.id = prr.user_id
         WHERE prr.id = ? AND prr.status = ? AND prr.expires_at > NOW()'
    );
    $stmt->execute([$requestId, 'pending']);
    $request = $stmt->fetch();

    if (!$request) {
        return null;
    }

    if ((int) $request['user_id'] === $hrUserId) {
        securityLog('password_reset_self_approval_blocked', "request_id={$requestId} user_id={$hrUserId}", $hrUserId);
        return null;
    }

    db()->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$request['user_email']]);

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    db()->prepare('INSERT INTO password_resets (email, token_hash, created_at) VALUES (?, ?, NOW())')
        ->execute([$request['user_email'], $tokenHash]);

    db()->prepare(
        'UPDATE password_reset_requests SET status = ?, action_by = ?, action_at = NOW() WHERE id = ?'
    )->execute(['approved', $hrUserId, $requestId]);

    securityLog('password_reset_approved', "request_id={$requestId} user_id={$request['user_id']}", $hrUserId);

    notifyUser(
        (int) $request['user_id'],
        'Password Reset Approved',
        'Your password reset request has been approved by HR. You may now reset your password using the link sent to your email.',
        BASE_URL . '/auth/forgot_password.php'
    );

    return $rawToken;
}

/**
 * Reject a reset request.
 */
function rejectResetRequest(int $requestId, int $hrUserId, ?string $ip = null): bool
{
    $stmt = db()->prepare(
        'SELECT prr.user_id
         FROM password_reset_requests prr
         WHERE prr.id = ? AND prr.status = ? AND prr.expires_at > NOW()'
    );
    $stmt->execute([$requestId, 'pending']);
    $request = $stmt->fetch();

    if (!$request) {
        return false;
    }

    db()->prepare(
        'UPDATE password_reset_requests SET status = ?, action_by = ?, action_at = NOW() WHERE id = ?'
    )->execute(['rejected', $hrUserId, $requestId]);

    securityLog('password_reset_rejected', "request_id={$requestId} user_id={$request['user_id']}", $hrUserId);

    return true;
}

// ────────────────────────────────────────────────────────────
// TOKEN CONSUMPTION — called from auth/reset_password.php
// ────────────────────────────────────────────────────────────

function passwordResetConsumeToken(string $rawToken): ?string
{
    $tokenHash = hash('sha256', $rawToken);

    $stmt = db()->prepare(
        'SELECT id, email, created_at FROM password_resets WHERE token_hash = ?'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $created = strtotime($row['created_at']);
    if ((time() - $created) > PASSWORD_RESET_TOKEN_TTL) {
        db()->prepare('DELETE FROM password_resets WHERE id = ?')->execute([$row['id']]);
        return null;
    }

    $email = $row['email'];
    db()->prepare('DELETE FROM password_resets WHERE id = ?')->execute([$row['id']]);

    return $email;
}

function passwordResetValidateToken(string $rawToken): ?string
{
    $tokenHash = hash('sha256', $rawToken);

    $stmt = db()->prepare(
        'SELECT id, email, created_at FROM password_resets WHERE token_hash = ?'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $created = strtotime($row['created_at']);
    if ((time() - $created) > PASSWORD_RESET_TOKEN_TTL) {
        db()->prepare('DELETE FROM password_resets WHERE id = ?')->execute([$row['id']]);
        return null;
    }

    return $row['email'];
}

function passwordResetApply(string $email, string $newPassword): bool
{
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = db()->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE email = ? AND is_active = 1 AND is_archived = 0');
    $stmt->execute([$hash, $email]);
    $applied = $stmt->rowCount() > 0;

    if ($applied) {
        $stmt2 = db()->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1 AND is_archived = 0');
        $stmt2->execute([$email]);
        $user = $stmt2->fetch();
        if ($user) {
            securityLog('password_reset_applied', "user_id={$user['id']}", (int) $user['id']);
        }
    }

    return $applied;
}

function passwordResetInvalidateSessions(int $userId): void
{
    db()->prepare('DELETE FROM password_resets WHERE email = (SELECT email FROM users WHERE id = ?)')
        ->execute([$userId]);
    db()->prepare('DELETE FROM password_reset_requests WHERE user_id = ? AND status = ?')
        ->execute([$userId, 'pending']);
}

// ────────────────────────────────────────────────────────────
// INTERNAL HELPERS
// ────────────────────────────────────────────────────────────

function maskEmailForDisplay(string $email): string
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return '***@***';
    }
    $local = $parts[0];
    $domain = $parts[1];
    $maskedLocal = mb_strlen($local) > 1
        ? mb_substr($local, 0, 1) . str_repeat('*', max(mb_strlen($local) - 1, 3))
        : '*';
    $domainParts = explode('.', $domain);
    if (count($domainParts) >= 2) {
        $tld = array_pop($domainParts);
        $maskedDomain = str_repeat('*', max(mb_strlen(implode('.', $domainParts)), 1)) . '.' . $tld;
    } else {
        $maskedDomain = str_repeat('*', max(mb_strlen($domain), 3));
    }
    return $maskedLocal . '@' . $maskedDomain;
}

function expireOldRequests(): void
{
    try {
        db()->prepare(
            'UPDATE password_reset_requests SET status = ? WHERE status = ? AND expires_at <= NOW()'
        )->execute(['rejected', 'pending']);
    } catch (Throwable $e) {
        error_log('expireOldRequests failed: ' . $e->getMessage());
    }
}

function notifyHRofResetRequest(int $userId, string $maskedEmail): void
{
    try {
        $stmt = db()->prepare('SELECT id FROM users WHERE role IN (?, ?) AND is_active = 1');
        $stmt->execute(['hr', 'manager']);
        $hrUsers = $stmt->fetchAll();

        foreach ($hrUsers as $hr) {
            notifyUser(
                (int) $hr['id'],
                'Password reset request',
                "A password reset was requested for {$maskedEmail}. Please review and approve or reject.",
                BASE_URL . '/modules/settings/password_resets.php'
            );
        }
    } catch (Throwable $e) {
        error_log('notifyHRofResetRequest failed: ' . $e->getMessage());
    }
}
