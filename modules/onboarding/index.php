<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_require();
    if ($_POST['action'] === 'start_onboarding') {
        $employeeId = (int) $_POST['employee_id'];
        $tasks = db()->query('SELECT id FROM onboarding_tasks ORDER BY sort_order')->fetchAll();

        $check = db()->prepare('SELECT COUNT(*) FROM employee_onboarding WHERE employee_id = ?');
        $check->execute([$employeeId]);
        if ((int) $check->fetchColumn() === 0) {
            $insert = db()->prepare('INSERT INTO employee_onboarding (employee_id, task_id) VALUES (?, ?)');
            foreach ($tasks as $task) {
                $insert->execute([$employeeId, $task['id']]);
            }
            flash('success', 'Onboarding checklist started for employee.');
        } else {
            flash('warning', 'Onboarding already exists for this employee.');
        }
        redirect(BASE_URL . '/modules/onboarding/index.php?employee_id=' . $employeeId);
    }

    if ($_POST['action'] === 'update_task') {
        $stmt = db()->prepare(
            'UPDATE employee_onboarding SET status=?, completed_date=?, completed_by=?, notes=? WHERE id=?'
        );
        $status = $_POST['status'];
        $completedDate = $status === 'completed' ? date('Y-m-d') : null;
        $stmt->execute([
            $status,
            $completedDate,
            trim($_POST['completed_by'] ?? ''),
            trim($_POST['notes'] ?? ''),
            (int) $_POST['onboarding_id'],
        ]);
        flash('success', 'Task updated.');
        redirect(BASE_URL . '/modules/onboarding/index.php?employee_id=' . (int) $_POST['employee_id']);
    }
}

$pageTitle = 'New Hire Onboarding';
$currentModule = 'onboarding';
require_once __DIR__ . '/../../includes/header.php';

$employees = getEmployees();
$selectedEmployeeId = (int) ($_GET['employee_id'] ?? 0);

$onboardingRows = [];
$selectedEmployee = null;

if ($selectedEmployeeId) {
    $stmt = db()->prepare('SELECT * FROM employees WHERE id = ?');
    $stmt->execute([$selectedEmployeeId]);
    $selectedEmployee = $stmt->fetch();

    $stmt = db()->prepare(
        'SELECT eo.*, ot.task_name, ot.category, ot.description
         FROM employee_onboarding eo
         JOIN onboarding_tasks ot ON eo.task_id = ot.id
         WHERE eo.employee_id = ?
         ORDER BY ot.sort_order'
    );
    $stmt->execute([$selectedEmployeeId]);
    $onboardingRows = $stmt->fetchAll();
}

$summary = db()->query(
    'SELECT e.id, e.employee_no, e.first_name, e.last_name,
            COUNT(eo.id) AS total_tasks,
            SUM(CASE WHEN eo.status = "completed" THEN 1 ELSE 0 END) AS completed_tasks
     FROM employees e
     LEFT JOIN employee_onboarding eo ON e.id = eo.employee_id
     WHERE e.status = "active"
     GROUP BY e.id
     HAVING total_tasks > 0
     ORDER BY e.last_name'
)->fetchAll();
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">New Hire Onboarding</h1>
        <p class="page-subtitle">Module 3 — Onboarding checklists and task tracking</p>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Start Onboarding</h2>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="start_onboarding">
        <select name="employee_id" required>
            <option value="">— Select Employee —</option>
            <?php foreach ($employees as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= $selectedEmployeeId === (int) $emp['id'] ? 'selected' : '' ?>>
                <?= e($emp['employee_no'] . ' — ' . $emp['first_name'] . ' ' . $emp['last_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Start Checklist</button>
    </form>
</section>

<?php if ($summary): ?>
<section class="panel fade-in-up" style="animation-delay:.2s">
    <h2>Onboarding Progress</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Progress</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($summary as $row):
                $pct = $row['total_tasks'] > 0 ? round(($row['completed_tasks'] / $row['total_tasks']) * 100) : 0;
            ?>
            <tr>
                <td><?= e($row['employee_no'] . ' — ' . $row['first_name'] . ' ' . $row['last_name']) ?></td>
                <td>
                    <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
                    <?= (int) $row['completed_tasks'] ?> / <?= (int) $row['total_tasks'] ?> (<?= $pct ?>%)
                </td>
                <td><a href="?employee_id=<?= (int) $row['id'] ?>" class="btn btn-sm">View Tasks</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php if ($selectedEmployee && $onboardingRows): ?>
<section class="panel fade-in-up" style="animation-delay:.3s">
    <h2>Checklist: <?= e($selectedEmployee['first_name'] . ' ' . $selectedEmployee['last_name']) ?></h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Task</th>
                <th>Category</th>
                <th>Status</th>
                <th>Completed</th>
                <th>Update</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($onboardingRows as $task): ?>
            <tr>
                <td>
                    <strong><?= e($task['task_name']) ?></strong>
                    <?php if ($task['description']): ?><br><small><?= e($task['description']) ?></small><?php endif; ?>
                </td>
                <td><?= e(ucfirst(str_replace('_', ' ', $task['category']))) ?></td>
                <td><?= statusBadge($task['status']) ?></td>
                <td><?= formatDate($task['completed_date']) ?></td>
                <td>
                    <form method="post" class="inline-form compact">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_task">
                        <input type="hidden" name="onboarding_id" value="<?= (int) $task['id'] ?>">
                        <input type="hidden" name="employee_id" value="<?= $selectedEmployeeId ?>">
                        <select name="status">
                            <?php foreach (['pending','in_progress','completed'] as $s): ?>
                            <option value="<?= $s ?>" <?= $task['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm">Save</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
