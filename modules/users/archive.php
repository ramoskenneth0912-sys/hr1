<?php
/**
 * ARCHIVE USER — soft-archive / restore workflow.
 *
 * Archive is a distinct action from Deactivate (modules/users/delete.php):
 *
 *   Deactivate -> users.is_active = 0  (revoke sign-in, keep in active list)
 *   Archive    -> users.is_archived = 1 (remove from the active Users list,
 *                 retain the account row + all historical records)
 *   Restore    -> users.is_archived = 0 (return account to the active list)
 *
 * The account row is NEVER deleted here. Login/password-reset/API auth reject
 * users.is_archived = 1. Every state change is POST-only and CSRF-protected.
 *
 * GET requests only display pages (list / confirmation) — never state changes.
 */
$pageTitle = 'Archived Users';
$currentModule = 'users';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_log.php';
requireHRorManager();
requireNotApplicant();

$actorId = (int) ($_SESSION['user_id'] ?? 0);

function loadTargetUser(int $userId): ?array
{
    $stmt = db()->prepare(
        "SELECT u.*, e.first_name, e.last_name, e.employee_no FROM users u
         LEFT JOIN employees e ON u.employee_id = e.id WHERE u.id = ?"
    );
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

// ========================================================
// POST handlers (state changes) — CSRF protected, RBAC gated
// ========================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();

    $postAction = $_POST['action'] ?? '';
    $user_id    = (int) ($_POST['id'] ?? 0);

    if ($user_id <= 0) {
        flash('danger', 'Invalid user account.');
        redirect(BASE_URL . '/modules/users/index.php');
    }

    $user = loadTargetUser($user_id);
    if (!$user) {
        flash('danger', 'User not found.');
        redirect(BASE_URL . '/modules/users/index.php');
    }

    if ($postAction === 'archive') {
        // Never allow an administrator to archive their own account.
        if ((int) $user['id'] === $actorId) {
            flash('danger', 'You cannot archive your own account.');
            redirect(BASE_URL . '/modules/users/index.php');
        }

        if ((int) ($user['is_archived'] ?? 0) === 1) {
            flash('info', "Account \"{$user['username']}\" is already archived.");
            redirect(BASE_URL . '/modules/users/archive.php');
        }

        db()->prepare(
            'UPDATE users SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?'
        )->execute([$actorId, $user_id]);

        securityLog('USER_ARCHIVED', "Archived account \"{$user['username']}\" — removed from the active Users list, records retained", $actorId, [
            'module'      => 'users',
            'target_type' => 'user',
            'target_id'   => $user_id,
            'status'      => 'success',
        ]);

        flash('success', 'User account archived. The account was removed from the active Users list; its record and history were retained.');
        redirect(BASE_URL . '/modules/users/index.php');
    }

    if ($postAction === 'restore') {
        if ((int) ($user['is_archived'] ?? 0) === 0) {
            flash('info', "Account \"{$user['username']}\" is not archived.");
            redirect(BASE_URL . '/modules/users/archive.php');
        }

        db()->prepare(
            'UPDATE users SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?'
        )->execute([$user_id]);

        securityLog('USER_RESTORED', "Restored archived account \"{$user['username']}\" back to the active Users list", $actorId, [
            'module'      => 'users',
            'target_type' => 'user',
            'target_id'   => $user_id,
            'status'      => 'success',
        ]);

        flash('success', 'User account restored to the active Users list.');
        redirect(BASE_URL . '/modules/users/index.php');
    }

    flash('danger', 'Invalid request.');
    redirect(BASE_URL . '/modules/users/archive.php');
}

// ========================================================
// GET: confirmation views (display only — no state change)
// ========================================================
$confirm = $_GET['confirm'] ?? '';
$confirmId = (int) ($_GET['id'] ?? 0);
$confirmUser = null;

if (in_array($confirm, ['archive', 'restore'], true) && $confirmId > 0) {
    $confirmUser = loadTargetUser($confirmId);
    if (!$confirmUser) {
        flash('danger', 'User not found.');
        redirect(BASE_URL . '/modules/users/archive.php');
    }
}

// Render chrome only after all redirect-capable logic has finished.
require_once __DIR__ . '/../../includes/header.php';

// ── Archive confirmation page ──────────────────────────────
if ($confirm === 'archive' && $confirmUser) {
    $selfAccount = (int) $confirmUser['id'] === $actorId;
    $alreadyArchived = ((int) ($confirmUser['is_archived'] ?? 0)) === 1;
    ?>
    <div class="page-header fade-in-up">
        <div>
            <h1 class="page-title">Archive User Account</h1>
            <p class="page-subtitle">Remove the account from the active Users list without deleting records</p>
        </div>
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Back</a>
    </div>

    <div class="panel fade-in-up" style="max-width:600px;animation-delay:.1s">
        <?php if ($alreadyArchived): ?>
            <div class="alert alert-info">
                <p>This account is already archived.</p>
            </div>
            <a href="<?= BASE_URL ?>/modules/users/archive.php" class="btn btn-outline">View Archived Users</a>
        <?php elseif ($selfAccount): ?>
            <div class="alert alert-danger">
                <p>You cannot archive your own account.</p>
            </div>
            <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Back</a>
        <?php else: ?>
            <div class="alert alert-warning">
                <p><strong>Archive this user?</strong></p>
                <p>Archived users will be removed from the active Users list but their account record and history will be retained.
                The account will no longer be able to sign in until it is restored.</p>
                <p style="margin-top:.5rem;font-weight:600;">Account: <?= e($confirmUser['username']) ?>
                    <?php if (!empty($confirmUser['first_name'])): ?>
                        (<?= e(trim($confirmUser['first_name'] . ' ' . $confirmUser['last_name'])) ?>)
                    <?php endif; ?>
                </p>
            </div>

            <form method="post" style="margin-top:1rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="id" value="<?= (int) $confirmUser['id'] ?>">
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Archive User</button>
                    <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// ── Restore confirmation page ──────────────────────────────
if ($confirm === 'restore' && $confirmUser) {
    ?>
    <div class="page-header fade-in-up">
        <div>
            <h1 class="page-title">Restore User Account</h1>
            <p class="page-subtitle">Return the archived account to the active Users list</p>
        </div>
        <a href="<?= BASE_URL ?>/modules/users/archive.php" class="btn btn-outline">← Back</a>
    </div>

    <div class="panel fade-in-up" style="max-width:600px;animation-delay:.1s">
        <?php if (((int) ($confirmUser['is_archived'] ?? 0)) === 0): ?>
            <div class="alert alert-info">
                <p>This account is not archived and is already in the active Users list.</p>
            </div>
            <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Back</a>
        <?php else: ?>
            <div class="alert alert-success">
                <p><strong>Restore this user?</strong></p>
                <p>The account will return to the active Users list and will be able to sign in again. No new account is created.</p>
                <p style="margin-top:.5rem;font-weight:600;">Account: <?= e($confirmUser['username']) ?></p>
            </div>

            <form method="post" style="margin-top:1rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="id" value="<?= (int) $confirmUser['id'] ?>">
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Restore</button>
                    <a href="<?= BASE_URL ?>/modules/users/archive.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// ========================================================
// GET: Archived Users list (default view)
// ========================================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT u.*, e.first_name, e.last_name, e.employee_no,
        ar.username AS archived_by_username
        FROM users u
        LEFT JOIN employees e ON u.employee_id = e.id
        LEFT JOIN users ar ON ar.id = u.archived_by
        WHERE u.is_archived = 1";

$params = [];
if ($search !== '') {
    $sql .= " AND (u.username LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY u.archived_at DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$archivedUsers = $stmt->fetchAll();
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Archived Users</h1>
        <p class="page-subtitle">Accounts removed from the active Users list — records retained</p>
    </div>
    <div class="btn-group">
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline">← Active Users</a>
        <a href="<?= BASE_URL ?>/modules/users/create.php" class="btn btn-primary">+ New Account</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <form method="get" class="inline-form filter-toolbar" style="margin-bottom: 1rem;">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search archived users by username or employee name..." style="flex:1;min-width:200px;max-width:360px;">
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if ($search !== ''): ?>
            <a href="<?= BASE_URL ?>/modules/users/archive.php" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <table class="data-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>Archived Date</th>
                <th>Archived By</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($archivedUsers)): ?>
                <tr><td colspan="7" class="empty">No archived users.</td></tr>
            <?php else: ?>
                <?php foreach ($archivedUsers as $u): ?>
                    <tr>
                        <td>
                            <?php if (!empty($u['first_name'])): ?>
                                <?= e(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?>
                            <?php else: ?>
                                <span style="color:var(--muted);">— No linked employee —</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($u['username']) ?></td>
                        <td>
                            <?php if ($u['role'] === 'hr'): ?>
                                <span class="badge badge-primary">HR</span>
                            <?php elseif ($u['role'] === 'manager'): ?>
                                <span class="badge badge-info">Manager</span>
                            <?php else: ?>
                                <span class="badge badge-success">Employee</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-secondary">Archived</span></td>
                        <td><?= e(date('M j, Y g:i A', strtotime($u['archived_at']))) ?></td>
                        <td><?= e($u['archived_by_username'] ?? '—') ?></td>
                        <td class="actions">
                            <a href="<?= BASE_URL ?>/modules/users/archive.php?confirm=restore&id=<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline">Restore</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>