<?php
$pageTitle = 'Department Management';
$currentModule = 'recruitment';
require_once __DIR__ . '/../../includes/header.php';
requireNotApplicant();

$departments = db()->query(
    'SELECT d.*, COUNT(j.id) AS job_count
     FROM departments d
     LEFT JOIN job_postings j ON j.department_id = d.id
     GROUP BY d.id
     ORDER BY d.name'
)->fetchAll();
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Department Management</h1>
        <p class="page-subtitle">Manage departments used across job postings, employees, and applicants</p>
    </div>
    <div class="btn-group">
        <a href="department_create.php" class="btn btn-primary">+ Add Department</a>
        <a href="index.php" class="btn btn-outline">← Back to Recruitment</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Departments</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Job Postings</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($departments)): ?>
            <tr><td colspan="5" class="empty">No departments yet.</td></tr>
            <?php else: foreach ($departments as $row): ?>
            <tr>
                <td><strong><?= e($row['code']) ?></strong></td>
                <td><?= e($row['name']) ?></td>
                <td><?= (int) $row['job_count'] ?></td>
                <td><?= formatDate($row['created_at']) ?></td>
                <td>
                    <a href="department_edit.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
