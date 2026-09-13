<?php
/**
 * MY SALARY — Employee Portal ESS module.
 * Session-scoped, employee-only salary and payroll overview.
 *
 * Salary data is read from the employees table. Payroll/payslip/deduction
 * functionality is reserved for HR4 and will light up automatically if
 * compatible database tables are added later. When payroll data does not
 * yet exist, the page renders the professional UI with clean empty states —
 * no records or amounts are ever invented.
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

// ---------------------------------------------------------------------------
// Employee profile guard
// ---------------------------------------------------------------------------
if (!$employeeId): ?>
<a class="ess-back" href="<?= BASE_URL ?>/index.php"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back</a>
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

// ---------------------------------------------------------------------------
// Employee salary data
// ---------------------------------------------------------------------------
$emp = db()->prepare(
    'SELECT e.*
     FROM employees e WHERE e.id = ?'
);
$emp->execute([$employeeId]);
$employee = $emp->fetch();

$name = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));

// Currency symbol
$currencySymbol = '₱';
$curr = strtoupper((string) ($employee['currency'] ?? 'PHP'));
if ($curr === 'USD')   { $currencySymbol = '$';  }
elseif ($curr === 'EUR')   { $currencySymbol = '€';  }
elseif ($curr === 'JPY')   { $currencySymbol = '¥';  }
elseif ($curr === 'SGD')   { $currencySymbol = 'S$'; }
elseif ($curr === 'GBP')   { $currencySymbol = '£';  }

$fmtMoney = static function ($value) use ($currencySymbol) {
    if ($value === null || $value === '' || (float) $value === 0.0) { return $currencySymbol . '0.00'; }
    return $currencySymbol . number_format((float) $value, 2);
};

// Current salary — the HR1 employees table stores it in `salary`. A few
// conventional alternate column names are honored for forward compatibility,
// but only real, non-zero values are ever displayed.
$salaryValue = null;
foreach (['salary', 'basic_salary', 'monthly_salary', 'salary_amount', 'rate', 'pay_rate'] as $col) {
    if (array_key_exists($col, $employee) && $employee[$col] !== null
        && $employee[$col] !== '' && (float) $employee[$col] > 0) {
        $salaryValue = $employee[$col];
        break;
    }
}
$salaryAssigned = $salaryValue !== null;

// Employment type display
$empTypeLabel = function ($type) {
    $map = [
        'regular'     => 'Regular',
        'contractual' => 'Contractual',
        'probationary'=> 'Probationary',
        'part_time'   => 'Part-time',
    ];
    return $map[$type] ?? e(ucfirst(str_replace('_', ' ', (string) $type)));
};

// Pay frequency display
$freqLabel = function ($f) {
    $map = [
        'monthly'      => 'Monthly',
        'weekly'       => 'Weekly',
        'semi_monthly' => 'Semi-monthly',
        'bi_weekly'    => 'Bi-weekly',
        'daily'        => 'Daily',
    ];
    return $map[$f] ?? e(ucfirst(str_replace('_', ' ', (string) $f)));
};

// Small muted icon set (existing HR1 inline-SVG stroke style)
$salaryIcon = static function (string $name): string {
    $icons = [
        'banknote'   => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'briefcase'  => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 12h18"/>',
        'calendar'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'layers'     => '<path d="M12 2 2 7l10 5 10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>',
        'money'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5c0-1 1-1.5 2.5-1.5s2.5.5 2.5 1.5-1 1.3-2.5 1.5-2.5.5-2.5 1.5 1 1.5 2.5 1.5 2.5-.5 2.5-1.5"/>',
        'check'      => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.25 2.25L15.5 10"/>',
        'receipt'    => '<path d="M4 2v20l2-1.5L8 22l2-1.5L12 22l2-1.5L16 22l2-1.5L20 22V2l-2 1.5L16 2l-2 1.5L12 2l-2 1.5L8 2 6 3.5 4 2z"/><path d="M9 12h6M9 8h6M9 16h4"/>',
    ];
    $body = $icons[$name] ?? $icons['check'];
    return '<svg class="salary-field-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
};

// ---------------------------------------------------------------------------
// Payroll / payslip data — safe to extend once HR4 tables exist.
// Only query tables that actually exist in this database.
// ---------------------------------------------------------------------------
$hasPayrollTable = false;
$payrollRows     = [];

try {
    $check = db()->prepare('SELECT COUNT(*) FROM information_schema.tables
                           WHERE table_schema = DATABASE()
                           AND table_name IN (?,?,?,?,?,?)');
    $check->execute(['payslips', 'payroll', 'payroll_records', 'payslip_records', 'payroll_runs', 'pay_history']);
    if ((int) $check->fetchColumn() > 0) {
        $hasPayrollTable = true;
    }
} catch (Throwable $e) {
    $hasPayrollTable = false;
}

if ($hasPayrollTable) {
    // Attempt to read from the most likely table; adapt column mapping once a
    // compatible schema is introduced. Fallback silently on any mismatch.
    foreach (['payslips', 'payroll_records', 'payroll', 'payslip_records', 'pay_history'] as $candidate) {
        try {
            $cols = db()->query("SELECT COUNT(*) FROM information_schema.columns
                                WHERE table_schema = DATABASE()
                                AND table_name = '$candidate'
                                AND column_name IN ('employee_id', 'pay_date')");
            if ((int) $cols->fetchColumn() >= 2) {
                $stmt = db()->prepare("SELECT * FROM `$candidate`
                                      WHERE employee_id = ?
                                      ORDER BY pay_date DESC, id DESC
                                      LIMIT 50");
                $stmt->execute([$employeeId]);
                $payrollRows = $stmt->fetchAll();
                break;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
}

$latest = $payrollRows ? $payrollRows[0] : null;

// Payroll Summary totals — computed from the employee's actual records only.
$summaryGross = 0.0; $summaryDeductions = 0.0; $summaryNet = 0.0;
foreach ($payrollRows as $row) {
    $summaryGross      += (float) ($row['gross_pay']  ?? 0);
    $summaryDeductions += (float) ($row['deductions'] ?? 0);
    $summaryNet        += (float) ($row['net_pay']    ?? 0);
}
?>

<a class="ess-back" href="<?= BASE_URL ?>/index.php"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back</a>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">My Salary</h1>
        <p class="page-subtitle">Salary and payroll information for <?= e($name) ?></p>
    </div>
</div>

<!-- ======================= SALARY OVERVIEW ============================== -->
<section class="panel fade-in-up">
    <h2>Salary Overview</h2>

    <div class="salary-grid">
        <div class="salary-field<?= $salaryAssigned ? ' salary-featured' : '' ?>">
            <span class="salary-label"><?= $salaryIcon('banknote') ?> Current Salary</span>
            <span class="salary-value"><?= $salaryAssigned ? $fmtMoney($salaryValue) : 'Not assigned' ?></span>
            <?php if ($salaryAssigned && !empty($employee['pay_frequency'])): ?>
            <span class="salary-hint">/ <?= e($freqLabel($employee['pay_frequency'])) ?></span>
            <?php endif; ?>
        </div>
        <div class="salary-field">
            <span class="salary-label"><?= $salaryIcon('clock') ?> Pay Frequency</span>
            <span class="salary-value"><?= $employee['pay_frequency'] ? e($freqLabel($employee['pay_frequency'])) : '—' ?></span>
        </div>
        <div class="salary-field">
            <span class="salary-label"><?= $salaryIcon('briefcase') ?> Employment Type</span>
            <span class="salary-value"><?= $employee['employment_type'] ? e($empTypeLabel($employee['employment_type'])) : '—' ?></span>
        </div>
        <div class="salary-field">
            <span class="salary-label"><?= $salaryIcon('calendar') ?> Hire Date</span>
            <span class="salary-value"><?= $employee['hire_date'] ? e(date('M j, Y', strtotime((string) $employee['hire_date']))) : '—' ?></span>
        </div>
        <div class="salary-field">
            <span class="salary-label"><?= $salaryIcon('layers') ?> Salary Grade</span>
            <span class="salary-value">—</span>
        </div>
        <div class="salary-field">
            <span class="salary-label"><?= $salaryIcon('money') ?> Currency</span>
            <span class="salary-value"><?= e($employee['currency'] ?? 'PHP') ?></span>
        </div>
        <div class="salary-field salary-field-wide">
            <span class="salary-label"><?= $salaryIcon('check') ?> Status</span>
            <span class="salary-value"><span class="badge badge-success">Active</span></span>
        </div>
    </div>

    <?php if (!$salaryAssigned): ?>
    <div class="salary-note">
        Please contact HR for salary information.
    </div>
    <?php endif; ?>
</section>

<!-- ========================= LATEST PAYSLIP ============================== -->
<section class="panel fade-in-up" style="animation-delay:.08s">
    <h2>Latest Payslip</h2>

    <?php if ($latest && isset($latest['gross_pay'])): ?>
    <div class="payslip-card">
        <div class="payslip-dates">
            <div class="detail-item">
                <label>Pay Period</label>
                <span><?= e($latest['pay_period'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <label>Pay Date</label>
                <span><?= e(date('M j, Y', strtotime((string) ($latest['pay_date'] ?? date('Y-m-d'))))) ?></span>
            </div>
        </div>
        <div class="payslip-summary">
            <div class="payslip-row">
                <span>Gross Pay</span>
                <strong><?= $fmtMoney($latest['gross_pay'] ?? 0) ?></strong>
            </div>
            <div class="payslip-row payslip-deduct">
                <span>Deductions</span>
                <strong>−<?= $fmtMoney($latest['deductions'] ?? 0) ?></strong>
            </div>
            <hr class="payslip-divider">
            <div class="payslip-row payslip-net">
                <span>Net Pay</span>
                <strong><?= $fmtMoney($latest['net_pay'] ?? 0) ?></strong>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="salary-empty-state">
        <?= $salaryIcon('receipt') ?>
        <div class="salary-empty-title">No payroll records yet</div>
        <div class="salary-empty-text">Payroll information will appear here once payroll processing has been configured.</div>
    </div>
    <?php endif; ?>
</section>

<!-- ======================== PAYROLL HISTORY ============================== -->
<section class="panel fade-in-up" style="animation-delay:.16s">
    <h2>Payroll History</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Pay Period</th>
                    <th>Pay Date</th>
                    <th>Gross Pay</th>
                    <th>Deductions</th>
                    <th>Net Pay</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payrollRows)): ?>
                <tr>
                    <td class="empty" colspan="6">
                        No payroll history available.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($payrollRows as $pr): ?>
                    <tr>
                        <td><strong><?= e($pr['pay_period'] ?? '—') ?></strong></td>
                        <td><?= e(date('M j, Y', strtotime((string) ($pr['pay_date'] ?? date('Y-m-d'))))) ?></td>
                        <td><?= $fmtMoney($pr['gross_pay'] ?? 0) ?></td>
                        <td class="salary-deduct-cell">−<?= $fmtMoney($pr['deductions'] ?? 0) ?></td>
                        <td><strong><?= $fmtMoney($pr['net_pay'] ?? 0) ?></strong></td>
                        <td class="actions"><span class="salary-action-none">—</span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ======================= PAYROLL SUMMARY ============================== -->
<?php if ($payrollRows): ?>
<section class="panel fade-in-up" style="animation-delay:.20s">
    <h2>Payroll Summary</h2>
    <div class="stats-grid stats-grid-3">
        <div class="kpi-card">
            <span class="kpi-value salary-sum-total"><?= $fmtMoney($summaryGross) ?></span>
            <span class="kpi-label">Total Gross Pay</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-value salary-sum-deduct">−<?= $fmtMoney($summaryDeductions) ?></span>
            <span class="kpi-label">Total Deductions</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-value salary-sum-net"><?= $fmtMoney($summaryNet) ?></span>
            <span class="kpi-label">Total Net Pay</span>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ======================== PAGE SCOPED STYLES ============================ -->
<style>
/* My Salary — scoped to the salary page only. Reuses the shared design
   tokens; only new visual rules are added here. */

/* Salary Overview — responsive 3-column information grid */
.salary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .9rem 1.5rem;
}
.salary-field {
    display: flex;
    flex-direction: column;
    gap: .3rem;
    padding: .35rem 0;
}
.salary-label {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    font-size: .68rem;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .04em;
}
.salary-field-icon { flex: 0 0 auto; color: var(--purple); opacity: .55; }
.salary-value {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-dark);
    line-height: 1.3;
    font-variant-numeric: tabular-nums;
}
.salary-featured .salary-value {
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--text-dark);
    letter-spacing: -.02em;
}
.salary-hint {
    font-size: .76rem;
    font-weight: 500;
    color: var(--muted);
}
.salary-field-wide { grid-column: 1 / -1; }

.salary-note {
    margin-top: 1rem;
    padding: .7rem .95rem;
    background: var(--surface-muted);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: .8rem;
    color: var(--muted);
    text-align: center;
}

/* Latest Payslip — centered professional empty state */
.salary-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .4rem;
    padding: 3rem 1rem;
    text-align: center;
    color: var(--muted);
}
.salary-empty-state .salary-field-icon {
    width: 38px;
    height: 38px;
    padding: 10px;
    margin-bottom: .35rem;
    color: var(--purple);
    opacity: .5;
    background: var(--surface-muted);
    border: 1px solid var(--border);
    border-radius: 12px;
}
.salary-empty-title {
    font-size: .98rem;
    font-weight: 600;
    color: var(--text-dark);
}
.salary-empty-text {
    max-width: 380px;
    font-size: .8rem;
    color: var(--muted);
    line-height: 1.5;
}

/* Latest Payslip (data) */
.payslip-card {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}
.payslip-dates {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
}
.payslip-summary {
    background: var(--surface-muted);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: .95rem 1.15rem;
}
.payslip-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: .88rem;
    padding: .28rem 0;
}
.payslip-row span { color: var(--muted); font-weight: 500; }
.payslip-row strong {
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    color: var(--text-dark);
}
.payslip-deduct strong { color: var(--status-warning); }
.payslip-divider { border: 0; border-top: 1px solid var(--border); margin: .5rem 0; }
.payslip-net strong { font-size: 1.05rem; font-weight: 700; }

/* Payroll History — deduction values and placeholder action */
.salary-deduct-cell { color: var(--muted); font-variant-numeric: tabular-nums; }
.salary-action-none { color: var(--muted); font-variant-numeric: tabular-nums; }

/* Payroll Summary tiles (rendered only when real records exist) */
.salary-sum-total { font-weight: 700; color: var(--text-dark); }
.salary-sum-deduct { color: var(--status-warning); }
.salary-sum-net { font-weight: 700; color: var(--text-dark); font-size: 1.15rem; }

/* Responsive */
@media (max-width: 860px) {
    .salary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .8rem 1.25rem; }
}
@media (max-width: 560px) {
    .salary-grid { grid-template-columns: 1fr; gap: .7rem; }
    .payslip-dates { grid-template-columns: 1fr; gap: .6rem; }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>