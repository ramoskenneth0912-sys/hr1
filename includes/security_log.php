<?php
/**
 * Security Event Logger / Audit Log — writes the audit trail to the
 * security_log table.
 *
 * Usage (rich, recommended):
 *     securityLog('USER_CREATED', 'Created account john.doe', $actorId, [
 *         'module'      => 'users',
 *         'target_type' => 'user',
 *         'target_id'   => $userId,
 *         'status'      => 'success',
 *     ]);
 *
 * Usage (backwards-compatible):
 *     securityLog('login_success', 'admin logged in');
 *     securityLog('password_reset_request', "user_id=5", 5);
 *
 * Every row captures: user_id (nullable), username, role, event_type, module,
 * target_type, target_id, ip_address, user_agent, details, status, created_at.
 *
 * NEVER logs: passwords, password hashes, tokens, session/CSRF secrets,
 * SMTP credentials, or API keys.
 */

/**
 * Append one entry to the audit log.
 *
 * @param string      $eventType Machine-readable action (e.g. USER_CREATED).
 * @param string|null $details   Human-readable description (no secrets).
 * @param int|null    $userId    Acting user account id (null for anonymous).
 * @param array       $context   Optional extra columns:
 *                               username, role, module, target_type,
 *                               target_id, user_agent, status.
 */
function securityLog(string $eventType, ?string $details = null, ?int $userId = null, array $context = []): void
{
    try {
        $username = isset($context['username']) ? (string) $context['username'] : null;
        $role     = isset($context['role']) ? (string) $context['role'] : null;

        // When the actor is known but no username/role was supplied, snapshot
        // them from the account now so the trail stays accurate even after the
        // account is later edited or removed.
        if ($userId !== null && $userId > 0 && ($username === null || $role === null)) {
            $stmt = db()->prepare('SELECT username, role FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            if ($row = $stmt->fetch()) {
                $username = $username ?? $row['username'];
                $role     = $role ?? $row['role'];
            }
        }

        $stmt = db()->prepare(
            'INSERT INTO security_log
                (user_id, username, role, event_type, module, target_type,
                 target_id, ip_address, user_agent, details, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $userId,
            $username !== null ? mb_substr($username, 0, 191) : null,
            $role !== null ? mb_substr($role, 0, 32) : null,
            mb_substr($eventType, 0, 64),
            isset($context['module']) ? mb_substr((string) $context['module'], 0, 64) : null,
            isset($context['target_type']) ? mb_substr((string) $context['target_type'], 0, 64) : null,
            isset($context['target_id']) ? (int) $context['target_id'] : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            isset($context['user_agent'])
                ? mb_substr((string) $context['user_agent'], 0, 512)
                : (isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 512) : null),
            mb_substr($details ?? '', 0, 1000),
            isset($context['status']) ? mb_substr((string) $context['status'], 0, 16) : null,
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

/**
 * Audit-log query with filters. Supported $filters keys:
 *     event  -> exact event_type (e.g. USER_CREATED)
 *     module -> exact module (e.g. users)
 *     status -> 'success' | 'failure'
 *     search -> LIKE against actor username / linked username / details
 *
 * Returns rows ordered newest-first. `actor_username` resolves the actor's
 * username from the snapshot column, falling back to the linked account.
 */
function getAuditLog(array $filters = [], int $limit = 50, int $offset = 0): array
{
    try {
        [$where, $params] = auditLogWhere($filters);
        $limit  = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        $sql = 'SELECT sl.*, COALESCE(NULLIF(sl.username, \'\'), u.username) AS actor_username
                FROM security_log sl
                LEFT JOIN users u ON u.id = sl.user_id';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY sl.id DESC LIMIT ? OFFSET ?';

        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge($params, [$limit, $offset]));
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('getAuditLog failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Count of audit-log rows matching the same $filters as getAuditLog().
 */
function countAuditLog(array $filters = []): int
{
    try {
        [$where, $params] = auditLogWhere($filters);
        $sql = 'SELECT COUNT(*) FROM security_log sl LEFT JOIN users u ON u.id = sl.user_id';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('countAuditLog failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Build a parameterized WHERE clause from validated filters.
 * Supported $filters keys:
 *     event      -> exact event_type (e.g. USER_CREATED)
 *     actions    -> array of event_type values (IN clause)
 *     module     -> exact module (e.g. users)
 *     status     -> 'success' | 'failure'
 *     user_id    -> actor account id (column sl.user_id)
 *     date_from  -> Y-m-d, entries created on/after this day
 *     date_to    -> Y-m-d, entries created on/before this day
 *     search     -> LIKE against actor snapshot username, linked username,
 *                   event_type, module and details; a numeric value also
 *                   matches target_id
 * @return array{0: string[], 1: array<int|string>}
 */
function auditLogWhere(array $filters): array
{
    $where   = [];
    $params  = [];

    $event    = trim((string) ($filters['event'] ?? ''));
    $module   = trim((string) ($filters['module'] ?? ''));
    $status   = trim((string) ($filters['status'] ?? ''));
    $search   = trim((string) ($filters['search'] ?? ''));
    $userId   = isset($filters['user_id']) ? (int) $filters['user_id'] : 0;
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo   = trim((string) ($filters['date_to'] ?? ''));
    $actions  = isset($filters['actions']) && is_array($filters['actions']) ? $filters['actions'] : [];

    if ($event !== '') {
        $where[]  = 'sl.event_type = ?';
        $params[] = mb_substr($event, 0, 64);
    }

    $actionList = [];
    foreach ($actions as $action) {
        $action = trim((string) $action);
        if ($action !== '') {
            $actionList[] = mb_substr($action, 0, 64);
        }
    }
    if (!empty($actionList)) {
        $where[] = 'sl.event_type IN (' . implode(',', array_fill(0, count($actionList), '?')) . ')';
        foreach ($actionList as $action) {
            $params[] = $action;
        }
    }

    if ($module !== '') {
        $where[]  = 'sl.module = ?';
        $params[] = mb_substr($module, 0, 64);
    }
    if ($status !== '') {
        $where[]  = 'sl.status = ?';
        $params[] = mb_substr($status, 0, 16);
    }
    if ($userId > 0) {
        $where[]  = 'sl.user_id = ?';
        $params[] = $userId;
    }
    if ($dateFrom !== '' && auditValidDate($dateFrom)) {
        $where[]  = 'sl.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '' && auditValidDate($dateTo)) {
        $where[]  = 'sl.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sqlSearch = '(COALESCE(sl.username, \'\') LIKE ?
                          OR COALESCE(u.username, \'\') LIKE ?
                          OR COALESCE(sl.event_type, \'\') LIKE ?
                          OR COALESCE(sl.module, \'\') LIKE ?
                          OR COALESCE(sl.details, \'\') LIKE ?';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        if (is_numeric($search) && (int) $search > 0) {
            $sqlSearch .= ' OR sl.target_id = ?';
            $params[]  = (int) $search;
        }
        $where[] = $sqlSearch . ')';
    }

    return [$where, $params];
}

/**
 * Whether a string is a valid Y-m-d calendar date.
 */
function auditValidDate(string $date): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * Human-readable label for an audit event type.
 * Covers the curated audit actions plus legacy snake_case events.
 */
function auditEventLabel(string $event): string
{
    static $labels = null;
    if ($labels === null) {
        $labels = [
            // User management (curated audit actions)
            'USER_CREATED'         => 'User Created',
            'USER_UPDATED'         => 'User Updated',
            'USER_DEACTIVATED'     => 'User Deactivated',
            'USER_REACTIVATED'     => 'User Reactivated',
            'USER_DELETED'         => 'User Removed',
            'USER_ARCHIVED'        => 'User Archived',
            'USER_RESTORED'        => 'User Restored',
            'USER_ROLE_CHANGED'    => 'Role Changed',
            'USER_PASSWORD_RESET'  => 'Password Reset',
            // Authentication
            'LOGIN_SUCCESS'        => 'Login Success',
            'LOGIN_FAILED'         => 'Login Failed',
            'LOGOUT'               => 'Logout',
            // Legacy security_log events
            'login_success'        => 'Login Success',
            'login_fail'           => 'Login Failed',
            'password_changed'     => 'Password Changed',
            'profile_updated'      => 'Profile Updated',
            'user_account_created' => 'User Created',
            'user_account_updated' => 'User Updated',
            'user_account_deactivated' => 'User Deactivated',
            'user_account_removed' => 'User Removed',
            'password_reset_request'  => 'Password Reset Request',
            'password_reset_approved' => 'Password Reset Approved',
            'password_reset_rejected' => 'Password Reset Rejected',
            'password_reset_applied'  => 'Password Reset Applied',
            'password_reset_self_approval_blocked' => 'Self Approval Blocked',
            'maintenance_mode_change' => 'Maintenance Mode Change',
            'api_key_update'       => 'API Key Updated',
            'employee_account_removed' => 'Employee Account Removed',
        ];
    }
    return $labels[$event] ?? ucfirst(str_replace('_', ' ', strtolower($event)));
}

/**
 * CSS badge class for an audit status value ('success'|'failure'|other).
 */
function auditStatusClass(?string $status): string
{
    $class = strtolower((string) $status);
    if ($class === 'success') {
        return 'badge-success';
    }
    if ($class === 'failure') {
        return 'badge-danger';
    }
    return 'badge-secondary';
}