<?php
/**
 * REMOVE USER ACCOUNT — permanent removal, separate from Deactivate.
 *
 * Deactivate (modules/users/delete.php) only sets is_active = 0 and is left
 * untouched. This handler physically removes the account row, but only when
 * that is safe:
 *   - It refuses (no changes) when the user is referenced by a RESTRICT
 *     foreign key that must not be destroyed (api_keys, employee_recognitions).
 *   - It detaches applicant records (applicants.user_id has no FK) so those
 *     records survive.
 *   - It deletes the account's own authentication/session artifacts only
 *     (api_tokens, notifications, notification_preferences,
 *     password_reset_requests) — the same rows the schema already cascades.
 *   - Every historical HR record references users with ON DELETE SET NULL,
 *     so it is preserved; only the account row disappears.
 *
 * State changes are POST-only, CSRF-protected, and RBAC-gated.
 */
$pageTitle = 'Remove User Account';
$currentModule = 'users';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_log.php';
requireHRorManager();
requireNotApplicant();

// Destructive operations are never performed on a GET request.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    flash('info', 'Please use the Remove action on the User Management page.');
    redirect(BASE_URL . '/modules/users/index.php');
}

csrf_require();

$user_id = (int) ($_POST['id'] ?? 0);
if ($user_id <= 0) {
    flash('danger', 'Invalid user account.');
    redirect(BASE_URL . '/modules/users/index.php');
}

$stmt = db()->prepare(
    "SELECT u.*, e.first_name, e.last_name FROM users u
     LEFT JOIN employees e ON u.employee_id = e.id WHERE u.id = ?"
);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    flash('danger', 'User not found.');
    redirect(BASE_URL . '/modules/users/index.php');
}

// Never allow the signed-in administrator to remove their own account.
if ((int) $user['id'] === (int) ($_SESSION['user_id'] ?? 0)) {
    flash('danger', 'You cannot remove your own account.');
    redirect(BASE_URL . '/modules/users/index.php');
}

try {
    db()->beginTransaction();

    // RESTRICT references: a physical DELETE would either fail or force us to
    // destroy records that must be kept. Block and report instead.
    $blockers = [];

    $chk = db()->prepare('SELECT COUNT(*) FROM api_keys WHERE created_by = ?');
    $chk->execute([$user_id]);
    if ((int) $chk->fetchColumn() > 0) {
        $blockers[] = 'API keys';
    }

    $chk = db()->prepare('SELECT COUNT(*) FROM employee_recognitions WHERE issuer_user_id = ?');
    $chk->execute([$user_id]);
    if ((int) $chk->fetchColumn() > 0) {
        $blockers[] = 'recognition records';
    }

    if (!empty($blockers)) {
        db()->rollBack();
        flash('danger', 'This account cannot be removed because it is referenced by '
            . implode(' and ', $blockers) . '. Deactivate it instead so those records are preserved.');
        redirect(BASE_URL . '/modules/users/index.php');
    }

    // applicants.user_id has no foreign key — detach it so applicant history survives.
    db()->prepare('UPDATE applicants SET user_id = NULL WHERE user_id = ?')->execute([$user_id]);

    // Remove only this account's own authentication/session artifacts. These are
    // the exact rows the users foreign keys already cascade; HR history is not touched.
    db()->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$user_id]);
    db()->prepare('DELETE FROM password_reset_requests WHERE user_id = ?')->execute([$user_id]);
    db()->prepare('DELETE FROM notification_preferences WHERE user_id = ?')->execute([$user_id]);
    db()->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$user_id]);

    // Physical removal of the account row. All historical HR records reference
    // users with ON DELETE SET NULL and are preserved.
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$user_id]);

    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log('user remove failed: ' . $e->getMessage());
    flash('danger', 'The user account could not be removed. No changes were made.');
    redirect(BASE_URL . '/modules/users/index.php');
}

securityLog('USER_DELETED', "Removed user account \"{$user['username']}\" — historical records preserved", (int) ($_SESSION['user_id'] ?? 0), [
    'module'      => 'users',
    'target_type' => 'user',
    'target_id'   => $user_id,
    'status'      => 'success',
]);

flash('success', 'User account removed. Historical records were preserved.');
redirect(BASE_URL . '/modules/users/index.php');
