<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$pageTitle = 'Terminated Employees';
$currentModule = 'hcm';
require_once __DIR__ . '/../../includes/header.php';

$employees = db()->query(
    'SELECT e.*, d.name AS department_name FROM employees e
     LEFT JOIN departments d ON e.department_id = d.id
     WHERE e.status = \'terminated\'
     ORDER BY e.last_name, e.first_name'
)->fetchAll();
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Terminated Employees</h1>
        <p class="page-subtitle">Historical records of terminated employees</p>
    </div>
    <a href="index.php" class="btn btn-outline">← Back</a>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <div class="table-wrap">
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
            <tr><td colspan="9" class="empty">No terminated employees found.</td></tr>
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
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
