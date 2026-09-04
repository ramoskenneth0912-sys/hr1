<?php
/**
 * MY SALARY — employee-facing salary and paycheck overview.
 * Salary data comes from the HR1 employees table.
 * Paycheck integration is reserved for the future HR4 payroll module.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

$user = getCurrentUser();
$employeeId = $user['employee_id'] ?? null;

$pageTitle = 'My Salary';
$currentModule = 'my-salary';
$bodyClass = 'page-dashboard';
require_once __DIR__ . '/../../includes/header.php';

if (!$employeeId): ?>
<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">My Salary</h1>
        <p class="page-subtitle">Employee Self-Service</p>
    </div>
</div>
<div class="alert alert-warning">No employee profile linked to your account. Please contact HR.</div>
<?php
require_once __DIR__ . '/../../includes/footer.php';
exit;
endif;

$emp = db()->prepare(
    'SELECT e.* FROM employees e WHERE e.id = ?'
);
$emp->execute([$employeeId]);
$employee = $emp->fetch();

$currencySymbol = '₱';
if (strtoupper((string) ($employee['currency'] ?? 'PHP')) === 'USD') { $currencySymbol = '$'; }
elseif (strtoupper((string) ($employee['currency'] ?? 'PHP')) === 'EUR') { $currencySymbol = '€'; }
elseif (strtoupper((string) ($employee['currency'] ?? 'PHP')) === 'JPY') { $currencySymbol = '¥'; }
elseif (strtoupper((string) ($employee['currency'] ?? 'PHP')) === 'SGD') { $currencySymbol = 'S$'; }
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">My Salary</h1>
        <p class="page-subtitle">Salary and paycheck information for <?= e(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')) ?></p>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Salary</h2>
    <?php if ($employee['salary']): ?>
    <div class="detail-grid">
        <div class="detail-item">
            <label>Salary</label>
            <span style="font-size:1.1rem;font-weight:700;color:var(--text-dark);">
                <?= $currencySymbol . number_format((float) $employee['salary'], 2) ?>
                <?= $employee['pay_frequency'] ? '/' . e(str_replace('_', ' ', $employee['pay_frequency'])) : '' ?>
            </span>
        </div>
        <div class="detail-item"><label>Salary Type</label><span><?= $employee['salary_type'] ? e(ucfirst(str_replace('_', ' ', $employee['salary_type']))) : '—' ?></span></div>
        <div class="detail-item"><label>Pay Frequency</label><span><?= $employee['pay_frequency'] ? e(ucfirst(str_replace('_', ' ', $employee['pay_frequency']))) : '—' ?></span></div>
        <div class="detail-item"><label>Currency</label><span><?= e($employee['currency'] ?? 'PHP') ?></span></div>
        <div class="detail-item"><label>Status</label><span class="badge badge-success">Active</span></div>
    </div>
    <?php else: ?>
    <div class="detail-grid">
        <div class="detail-item full-width">
            <label>Salary</label>
            <span style="color:var(--muted);">Not yet assigned. Please contact HR.</span>
        </div>
    </div>
    <?php endif; ?>
</section>

<section class="panel fade-in-up" style="animation-delay:.2s">
    <h2>Paycheck</h2>
    <div class="detail-grid">
        <div class="detail-item full-width">
            <label>Status</label>
            <span style="color:var(--muted);">No paycheck records available. Paycheck information will appear here once payroll processing is connected.</span>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
