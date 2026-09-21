<?php
$pageTitle = 'User Management';
$currentModule = 'users';
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
requireNotApplicant();
require_once __DIR__ . '/../../includes/header.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT u.*, e.first_name, e.last_name, e.employee_no,
        d.name AS department_name
        FROM users u
        LEFT JOIN employees e ON u.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id";

$params = [];
$where = ['u.is_archived = 0'];
if ($search !== '') {
    $where[] = "(u.username LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= " ORDER BY u.created_at DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">User Management</h1>
        <p class="page-subtitle">System Account &amp; Access Management</p>
    </div>
    <div class="btn-group">
        <a href="<?= BASE_URL ?>/modules/users/audit_log.php" class="btn btn-outline">Audit Log</a>
        <a href="<?= BASE_URL ?>/modules/users/archive.php" class="btn btn-outline">Archived Users</a>
        <a href="<?= BASE_URL ?>/modules/users/create.php" class="btn btn-primary">+ New Account</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <form method="get" class="inline-form filter-toolbar" style="margin-bottom: 1rem;">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by username or employee name..." style="flex:1;min-width:200px;max-width:360px;">
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if ($search !== ''): ?>
            <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <table class="data-table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Linked Employee</th>
                <th>Role</th>
                <th>Department</th>
                <th>Account Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="6" class="empty">No users found.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= e($u['username']) ?></td>
                        <td>
                            <?php if (!empty($u['first_name'])): ?>
                                <a href="<?= BASE_URL ?>/modules/hcm/view.php?id=<?= (int) $u['employee_id'] ?>">
                                    <?= e(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--muted);">— No linked employee —</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['role'] === 'hr'): ?>
                                <span class="badge badge-primary">HR</span>
                            <?php elseif ($u['role'] === 'manager'): ?>
                                <span class="badge badge-info">Manager</span>
                            <?php else: ?>
                                <span class="badge badge-success">Employee</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($u['department_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <a href="<?= BASE_URL ?>/modules/users/edit.php?id=<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                            <a href="<?= BASE_URL ?>/modules/users/delete.php?id=<?= (int) $u['id'] ?>" class="btn btn-sm" style="color:var(--danger);">Deactivate</a>
                            <a href="<?= BASE_URL ?>/modules/users/archive.php?confirm=archive&id=<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline">Archive</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
