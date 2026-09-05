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

$pageTitle = 'Salary — ' . $employee['employee_no'];
$currentModule = 'hcm';
require_once __DIR__ . '/../../includes/header.php';

$currencySymbol = '₱';
if (strtoupper((string) ($employee['currency'] ?? 'PHP')) === 'USD') { $currencySymbol = '$'; }
elseif (strtoupper((string) ($employee['currency'] ?? 'PHP')) === 'EUR') { $currencySymbol = '€'; }
elseif (strtoupper((string) ($employee['currency'] ?? 'PHP')) === 'JPY') { $currencySymbol = '¥'; }
elseif (strtoupper((string) ($employee['currency'] ?? 'PHP')) === 'SGD') { $currencySymbol = 'S$'; }
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Salary</h1>
        <p class="page-subtitle"><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?> — <?= e($employee['employee_no']) ?></p>
    </div>
    <div class="btn-group">
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline">← Profile</a>
        <?php if ($employee['status'] !== 'terminated'): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-primary">Edit</a>
        <?php endif; ?>
    </div>
</div>

<section class="panel detail-grid fade-in-up" style="animation-delay:.1s">
    <div class="detail-item"><label>Employee</label><span><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></span></div>
    <div class="detail-item"><label>Current Role</label><span><?= e($employee['job_title']) ?></span></div>
    <div class="detail-item"><label>Department</label><span><?= e($employee['department_name'] ?? '—') ?></span></div>
    <div class="detail-item"><label>Employment Status</label><span><?= statusBadge($employee['status']) ?></span></div>
</section>

<section class="panel fade-in-up" style="animation-delay:.15s">
    <h2>Salary Information</h2>
    <div class="detail-grid">
        <div class="detail-item">
            <label>Salary</label>
            <span>
                <?php if ($employee['salary']): ?>
                    <?= $currencySymbol . number_format((float) $employee['salary'], 2) ?>
                    <?= $employee['pay_frequency'] ? '/' . e(str_replace('_', ' ', $employee['pay_frequency'])) : '' ?>
                <?php else: ?>
                    Not yet assigned
                <?php endif; ?>
            </span>
        </div>
        <div class="detail-item">
            <label>Salary Type</label>
            <span><?= $employee['salary_type'] ? e(ucfirst(str_replace('_', ' ', $employee['salary_type']))) : '—' ?></span>
        </div>
        <div class="detail-item">
            <label>Pay Frequency</label>
            <span><?= $employee['pay_frequency'] ? e(ucfirst(str_replace('_', ' ', $employee['pay_frequency']))) : '—' ?></span>
        </div>
        <div class="detail-item">
            <label>Currency</label>
            <span><?= e($employee['currency'] ?? 'PHP') ?></span>
        </div>
        <div class="detail-item">
            <label>Salary Status</label>
            <span><?= $employee['salary'] ? 'Active' : 'Not yet assigned' ?></span>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
