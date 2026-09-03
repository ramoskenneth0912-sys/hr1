<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
$pageTitle = 'Recruitment Management';
$currentModule = 'recruitment';
require_once __DIR__ . '/../../includes/header.php';

$jobs = db()->query(
    'SELECT j.*, d.name AS department_name FROM job_postings j
     LEFT JOIN departments d ON j.department_id = d.id ORDER BY j.created_at DESC'
)->fetchAll();
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Recruitment Management</h1>
        <p class="page-subtitle"></p>
    </div>
    <div class="btn-group">
        <a href="job_create.php" class="btn btn-primary">+ Job Posting</a>
        <a href="departments.php" class="btn btn-outline">Departments</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2 id="job-postings">Job Postings</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Job Code</th>
                <th>Title</th>
                <th>Department</th>
                <th>Vacancies</th>
                <th>Status</th>
                <th>Posted</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($jobs)): ?>
            <tr><td colspan="7" class="empty">No job postings yet.</td></tr>
            <?php else: foreach ($jobs as $row): ?>
            <tr>
                <td><?= e($row['job_code']) ?></td>
                <td><?= e($row['title']) ?></td>
                <td><?= e($row['department_name'] ?? '—') ?></td>
                <td><?= (int) $row['vacancies'] ?></td>
                <td><?= statusBadge($row['status']) ?></td>
                <td><?= formatDate($row['posted_date']) ?></td>
                <td><a href="job_edit.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline">Edit</a></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
