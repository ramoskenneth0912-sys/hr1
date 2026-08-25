<?php
$pageTitle = 'Core Human Capital Management';
$currentModule = 'hcm';
require_once __DIR__ . '/../../includes/header.php';
requireHRorManager();

$employees = db()->query(
    'SELECT e.*, d.name AS department_name FROM employees e
     LEFT JOIN departments d ON e.department_id = d.id
     ORDER BY e.last_name, e.first_name'
)->fetchAll();
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Core HCM</h1>
        <p class="page-subtitle">Module 4 — Human Capital Management</p>
    </div>
    <a href="create.php" class="btn btn-primary">+ New Employee</a>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <table class="data-table">
        <thead>
            <tr>
                <th>Employee No.</th>
                <th>Name</th>
                <th>Email</th>
                <th>Job Title</th>
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
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
