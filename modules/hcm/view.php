<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT e.*, d.name AS department_name FROM employees e
     LEFT JOIN departments d ON e.department_id = d.id WHERE e.id = ?'
);
$stmt->execute([$id]);
$employee = $stmt->fetch();

if (!$employee) {
    flash('danger', 'Employee not found.');
    redirect(BASE_URL . '/modules/hcm/index.php');
}

$history = db()->prepare(
    'SELECT * FROM employment_history WHERE employee_id = ? ORDER BY event_date DESC'
);
$history->execute([$id]);

$pageTitle = $employee['employee_no'];
$currentModule = 'hcm';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title"><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></h1>
        <p class="page-subtitle"><?= e($employee['employee_no']) ?> — <?= statusBadge($employee['status']) ?></p>
    </div>
    <div class="btn-group">
        <a href="edit.php?id=<?= $id ?>" class="btn btn-primary">Edit</a>
        <a href="<?= BASE_URL ?>/modules/onboarding/index.php?employee_id=<?= $id ?>" class="btn btn-outline">Onboarding</a>
        <a href="index.php" class="btn btn-outline">← Back</a>
    </div>
</div>

<section class="panel detail-grid fade-in-up" style="animation-delay:.1s">
    <div class="detail-item"><label>Email</label><span><?= e($employee['email']) ?></span></div>
    <div class="detail-item"><label>Phone</label><span><?= e($employee['phone'] ?: '—') ?></span></div>
    <div class="detail-item"><label>Job Title</label><span><?= e($employee['job_title']) ?></span></div>
    <div class="detail-item"><label>Department</label><span><?= e($employee['department_name'] ?? '—') ?></span></div>
    <div class="detail-item"><label>Employment Type</label><span><?= e(ucfirst(str_replace('_', ' ', $employee['employment_type']))) ?></span></div>
    <div class="detail-item"><label>Hire Date</label><span><?= formatDate($employee['hire_date']) ?></span></div>
    <div class="detail-item"><label>Salary</label><span><?= $employee['salary'] ? '₱' . number_format((float) $employee['salary'], 2) : '—' ?></span></div>
</section>

<section class="panel fade-in-up" style="animation-delay:.2s">
    <h2>Employment History</h2>
    <table class="data-table">
        <thead>
            <tr><th>Date</th><th>Event</th><th>Description</th><th>Recorded By</th></tr>
        </thead>
        <tbody>
            <?php foreach ($history as $h): ?>
            <tr>
                <td><?= formatDate($h['event_date']) ?></td>
                <td><?= e(ucfirst(str_replace('_', ' ', $h['event_type']))) ?></td>
                <td><?= e($h['description']) ?></td>
                <td><?= e($h['recorded_by'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
