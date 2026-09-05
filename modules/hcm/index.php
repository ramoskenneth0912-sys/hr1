<?php
$pageTitle = 'Core Human Capital Management';
$currentModule = 'hcm';
require_once __DIR__ . '/../../includes/header.php';
requireHRorManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'remove_employee') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);

        if ($employeeId <= 0) {
            flash('danger', 'Invalid employee.');
            redirect(BASE_URL . '/modules/hcm/index.php');
        }

        // Load the employee row to verify it exists (IDOR protection).
        $stmt = db()->prepare('SELECT * FROM employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
        if (!$employee) {
            flash('danger', 'Employee not found.');
            redirect(BASE_URL . '/modules/hcm/index.php');
        }

        $currentUser = getCurrentUser();

        // Self-removal protection: never deactivate your own currently
        // logged-in account through this action.
        if ($currentUser && (int) ($currentUser['employee_id'] ?? 0) === $employeeId) {
            flash('danger', 'You cannot remove your own currently logged-in account.');
            redirect(BASE_URL . '/modules/hcm/index.php');
        }

        $newStatus = 'terminated';
        if ($employee['status'] === $newStatus) {
            flash('info', 'That employee account is already ' . $newStatus . '.');
            redirect(BASE_URL . '/modules/hcm/index.php');
        }

        try {
            db()->beginTransaction();

            // Soft-deactivate any linked login account (is_active=0 disables
            // every authentication path). Historical HR records are preserved.
            db()->prepare('UPDATE users SET is_active = 0 WHERE employee_id = ? AND is_active = 1')
                ->execute([$employeeId]);

            // Deactivate the employee record (no longer an active account).
            db()->prepare('UPDATE employees SET status = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$newStatus, $employeeId]);

            // Preserve an audit trail of the lifecycle transition.
            $actor = $currentUser
                ? trim($currentUser['first_name'] . ' ' . $currentUser['last_name'])
                : 'System';
            db()->prepare(
                'INSERT INTO employment_history (employee_id, event_type, event_date, description, recorded_by)
                 VALUES (?, ?, CURDATE(), ?, ?)'
            )->execute([
                $employeeId,
                'termination',
                'Employee account removed/deactivated (status: ' . $newStatus . ').',
                $actor,
            ]);

            db()->commit();

            require_once __DIR__ . '/../../includes/security_log.php';
            securityLog('employee_account_removed', "employee_id={$employeeId}", $_SESSION['user_id'] ?? null);

            flash('success', 'Employee account for ' . $employee['first_name'] . ' ' . $employee['last_name']
                . ' removed/deactivated. Historical records preserved.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('hcm remove_employee failed: ' . $e->getMessage());
            flash('danger', 'The employee account could not be removed.');
        }

        redirect(BASE_URL . '/modules/hcm/index.php');
    }
}

$employees = db()->query(
    'SELECT e.*, d.name AS department_name FROM employees e
     LEFT JOIN departments d ON e.department_id = d.id
     WHERE e.status != \'terminated\'
     ORDER BY e.employee_no DESC'
)->fetchAll();
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Core HCM</h1>
        <p class="page-subtitle">Employee &amp; Employment Management</p>
    </div>
    <div class="btn-group">
        <a href="terminated.php" class="btn btn-outline">Terminated Employees</a>
        <a href="create.php" class="btn btn-primary">+ New Employee</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <table class="data-table">
        <thead>
            <tr>
                <th>Employee No.</th>
                <th>Name</th>
                <th>Email</th>
                <th>Current Role</th>
                <th>Department</th>
                <th>Type</th>
                <th>Status</th>
                <th>Hire Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($employees)): ?>
            <tr><td colspan="9" class="empty">No employees yet. <a href="create.php">Add employee</a>.</td></tr>
            <?php else: foreach ($employees as $row): ?>
            <tr>
                <td><?= e($row['employee_no']) ?></td>
                <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                <td><?= e($row['email']) ?></td>
                <td><?= e($row['job_title']) ?></td>
                <td><?= e($row['department_name'] ?? '—') ?></td>
                <td><?= e(ucfirst(str_replace('_', ' ', $row['employment_type']))) ?></td>
                <td><?= statusBadge($row['status']) ?></td>
                <td><?= formatDate($row['hire_date']) ?></td>
                <td class="actions">
                    <a href="view.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm">View</a>
                    <a href="edit.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                    <?php if ($row['status'] !== 'terminated'): ?>
                    <form method="post" class="inline-form" style="display:inline;margin:0;"
                          onsubmit="return confirm('Remove Employee Account?\n\nAre you sure you want to remove the account for:\n<?= e($row['first_name']) ?> <?= e($row['last_name']) ?> (<?= e($row['employee_no']) ?>)\n\nThis action will remove/deactivate this employee account.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_employee">
                        <input type="hidden" name="employee_id" value="<?= (int) $row['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline">Remove</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
