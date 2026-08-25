<?php
/**
 * Security Event Logger — writes audit trail to the security_log table.
 *
 * Usage:  securityLog('login_success', 'admin logged in');
 *         securityLog('password_reset_request', "user_id=5", 5);
 *
 * Every row includes: user_id (nullable), event_type, ip_address, details, timestamp.
 * Never logs: passwords, hashes, tokens, session tokens, SMTP credentials, API keys.
 */
function securityLog(string $eventType, ?string $details = null, ?int $userId = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO security_log (user_id, event_type, ip_address, details) VALUES (?,?,?,?)'
        );
        $stmt->execute([
            $userId,
            substr($eventType, 0, 64),
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr($details ?? '', 0, 1000),
        ]);
    } catch (Throwable $e) {
        error_log('securityLog failed: ' . $e->getMessage());
    }
}

/**
 * Retrieve the N most recent security log entries.
 * Returns an array of associative rows ordered by created_at DESC.
 */
function getSecurityLog(int $limit = 50, int $offset = 0): array
{
    try {
        $stmt = db()->prepare(
            'SELECT sl.*, u.username, u.email
             FROM security_log sl
             LEFT JOIN users u ON u.id = sl.user_id
             ORDER BY sl.created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('getSecurityLog failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Retrieve security log entries for a specific user.
 */
function getSecurityLogForUser(int $userId, int $limit = 20): array
{
    try {
        $stmt = db()->prepare(
            'SELECT sl.*
             FROM security_log sl
             WHERE sl.user_id = ?
             ORDER BY sl.created_at DESC
             LIMIT ?'
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('getSecurityLogForUser failed: ' . $e->getMessage());
        return [];
    }
}
