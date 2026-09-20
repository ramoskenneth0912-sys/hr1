<?php
$pageTitle = 'Audit Log';
$currentModule = 'users';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_log.php';
requireHRorManager();
requireNotApplicant();
require_once __DIR__ . '/../../includes/header.php';

// ── Filter state ─────────────────────────────────────────────────
$auditQ         = trim($_GET['audit_q'] ?? '');
$auditAction    = trim($_GET['audit_action'] ?? '');
$auditUserId    = (int) ($_GET['audit_user'] ?? 0);
$auditStatus    = trim($_GET['audit_status'] ?? '');
$auditDateFrom  = trim($_GET['audit_date_from'] ?? '');
$auditDateTo    = trim($_GET['audit_date_to'] ?? '');
$auditPerPage   = 25;
$auditPage      = max(1, (int) ($_GET['audit_page'] ?? 1));

// Action category -> set of machine event types. Categories keep the filter
// user-friendly while the IN clause matches every underlying event.
$auditActionOptions = [
    ''               => 'All',
    'login'          => 'Login',
    'logout'         => 'Logout',
    'create'         => 'Create',
    'update'         => 'Update',
    'deactivate'     => 'Deactivate',
    'reactivate'     => 'Reactivate',
    'archive'        => 'Archive',
    'restore'        => 'Restore',
    'delete'         => 'Delete',
    'role_change'    => 'Role Change',
    'password_reset' => 'Password Reset',
];
$auditActionEvents = [
    'login'          => ['LOGIN_SUCCESS', 'LOGIN_FAILED', 'LOGOUT', 'login_success', 'login_fail'],
    'logout'         => ['LOGOUT'],
    'create'         => ['USER_CREATED', 'user_account_created'],
    'update'         => ['USER_UPDATED', 'user_account_updated'],
    'deactivate'     => ['USER_DEACTIVATED', 'user_account_deactivated'],
    'reactivate'     => ['USER_REACTIVATED'],
    'archive'        => ['USER_ARCHIVED'],
    'restore'        => ['USER_RESTORED'],
    'delete'         => ['USER_DELETED', 'user_account_removed'],
    'role_change'    => ['USER_ROLE_CHANGED'],
    'password_reset' => ['USER_PASSWORD_RESET', 'password_reset_request', 'password_reset_approved', 'password_reset_rejected', 'password_reset_applied'],
];

$filters = [];
if ($auditQ !== '') {
    $filters['search'] = $auditQ;
}
if ($auditAction !== '' && isset($auditActionEvents[$auditAction])) {
    $filters['actions'] = $auditActionEvents[$auditAction];
} else {
    $auditAction = '';
}
if ($auditUserId > 0) {
    $filters['user_id'] = $auditUserId;
}
if ($auditStatus !== '' && in_array($auditStatus, ['success', 'failure'], true)) {
    $filters['status'] = $auditStatus;
} else {
    $auditStatus = '';
}
if ($auditDateFrom !== '' && auditValidDate($auditDateFrom)) {
    $filters['date_from'] = $auditDateFrom;
} else {
    $auditDateFrom = '';
}
if ($auditDateTo !== '' && auditValidDate($auditDateTo)) {
    $filters['date_to'] = $auditDateTo;
} else {
    $auditDateTo = '';
}

$filterActive = !empty($filters);

// ── User dropdown for the "User" filter ────────────────────────
$auditUsers = db()->query('SELECT id, username FROM users ORDER BY username ASC')->fetchAll();

// ── Data + server-side pagination ──────────────────────────────
$auditTotal = countAuditLog($filters);
$auditPages = max(1, (int) ceil($auditTotal / $auditPerPage));
if ($auditPage > $auditPages) {
    $auditPage = $auditPages;
}
$auditRows = getAuditLog($filters, $auditPerPage, ($auditPage - 1) * $auditPerPage);

function auditPageUrl(array $extra): string
{
    $q = [];
    foreach (['audit_q', 'audit_action', 'audit_user', 'audit_status', 'audit_date_from', 'audit_date_to'] as $key) {
        if (isset($_GET[$key]) && trim((string) $_GET[$key]) !== '') {
            $q[$key] = trim((string) $_GET[$key]);
        }
    }
    $q = array_merge($q, $extra);
    return BASE_URL . '/modules/users/audit_log.php?' . http_build_query($q);
}
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Audit Log</h1>
        <p class="page-subtitle">Monitor user-account management and sign-in activity</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Back to User Management</a>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <form method="get" class="inline-form filter-toolbar" style="gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;">
        <input type="text" name="audit_q" value="<?= e($auditQ) ?>" placeholder="Search user, action, module, details or target ID..." style="flex:1;min-width:220px;max-width:340px;">

        <select name="audit_action" aria-label="Filter by action">
            <option value="">Action: All</option>
            <?php foreach ($auditActionOptions as $key => $label): ?>
                <?php if ($key === ''): continue; endif; ?>
                <option value="<?= e($key) ?>" <?= $auditAction === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="audit_user" aria-label="Filter by user">
            <option value="">User: All</option>
            <?php foreach ($auditUsers as $auditUser): ?>
                <option value="<?= (int) $auditUser['id'] ?>" <?= $auditUserId === (int) $auditUser['id'] ? 'selected' : '' ?>><?= e($auditUser['username']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="audit_status" aria-label="Filter by status">
            <option value="">Status: All</option>
            <option value="success" <?= $auditStatus === 'success' ? 'selected' : '' ?>>Success</option>
            <option value="failure" <?= $auditStatus === 'failure' ? 'selected' : '' ?>>Failed</option>
        </select>

        <input type="date" name="audit_date_from" value="<?= e($auditDateFrom) ?>" aria-label="Date from">
        <input type="date" name="audit_date_to" value="<?= e($auditDateTo) ?>" aria-label="Date to">

        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if ($filterActive): ?>
            <a href="<?= BASE_URL ?>/modules/users/audit_log.php" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Details</th>
                    <th>IP Address</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($auditRows)): ?>
                    <tr><td colspan="8" class="empty">No audit log entries found.</td></tr>
                <?php else: ?>
                    <?php foreach ($auditRows as $log): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td>
                            <td>
                                <?php if (!empty($log['actor_username'])): ?>
                                    <strong><?= e($log['actor_username']) ?></strong>
                                <?php else: ?>
                                    <span style="color:var(--muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($log['role'] ?? '—') ?></td>
                            <td>
                                <span class="badge badge-info"><?= e(auditEventLabel($log['event_type'])) ?></span>
                                <small style="display:block;color:var(--muted);font-size:.7rem;"><?= e($log['event_type']) ?></small>
                            </td>
                            <td>
                                <?php if ($log['module'] !== null && $log['module'] !== ''): ?>
                                    <?= e(ucfirst($log['module'])) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="audit-details" style="max-width:360px;"><?= e($log['details'] ?? '') ?></td>
                            <td style="white-space:nowrap;"><?= e($log['ip_address'] ?? '—') ?></td>
                            <td>
                                <?php if ($log['status'] === null || $log['status'] === ''): ?>
                                    <span style="color:var(--muted);">—</span>
                                <?php else: ?>
                                    <span class="badge <?= e(auditStatusClass($log['status'])) ?>"><?= e(ucfirst($log['status'])) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($auditTotal > 0): ?>
        <div class="btn-group" style="justify-content:space-between;align-items:center;margin-top:1rem;">
            <small style="color:var(--muted);">
                Showing <?= (($auditPage - 1) * $auditPerPage + 1) ?>–<?= min($auditPage * $auditPerPage, $auditTotal) ?> of <?= $auditTotal ?> entries
            </small>
            <div style="display:flex;gap:.5rem;">
                <?php if ($auditPage > 1): ?>
                    <a href="<?= e(auditPageUrl(['audit_page' => $auditPage - 1])) ?>" class="btn btn-sm btn-outline">← Prev</a>
                <?php endif; ?>
                <?php if ($auditPage < $auditPages): ?>
                    <a href="<?= e(auditPageUrl(['audit_page' => $auditPage + 1])) ?>" class="btn btn-sm btn-outline">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>