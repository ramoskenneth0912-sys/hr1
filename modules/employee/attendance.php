<?php
/**
 * MY ATTENDANCE — Employee Portal ESS module.
 * Session-scoped, employee-only attendance monitoring.
 *
 * Attendance records are read from the HR1 database when an `attendance`
 * table exists. If the database does not provide attendance data yet, the
 * page renders the professional UI with a clean empty state — no records
 * are ever invented.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

$user = getCurrentUser();
$employeeId = $user['employee_id'] ?? null;

$pageTitle = 'My Attendance';
$currentModule = 'my-attendance';
$bodyClass = 'page-dashboard';
require_once __DIR__ . '/../../includes/header.php';

if (!$employeeId): ?>
<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">My Attendance</h1>
        <p class="page-subtitle">View your attendance records, working hours, lateness, and overtime.</p>
    </div>
</div>
<div class="alert alert-warning">No employee profile linked to your account. Please contact HR.</div>
<?php
require_once __DIR__ . '/../../includes/footer.php';
exit;
endif;

// ---- Period selector -------------------------------------------------------
$period = isset($_GET['period']) ? (string) $_GET['period'] : 'week';
$periods = [
    'today'      => 'Today',
    'week'       => 'This Week',
    'month'      => 'This Month',
    'prev_month' => 'Previous Month',
];
if (!isset($periods[$period])) { $period = 'week'; }

switch ($period) {
    case 'today':
        $start = date('Y-m-d');
        $end   = date('Y-m-d');
        $rangeLabel = date('D, M j, Y');
        break;
    case 'month':
        $start = date('Y-m-01');
        $end   = date('Y-m-t');
        $rangeLabel = date('F Y');
        break;
    case 'prev_month':
        $start = date('Y-m-01', strtotime('first day of last month'));
        $end   = date('Y-m-t', strtotime('last day of last month'));
        $rangeLabel = date('F Y', strtotime($start));
        break;
    case 'week':
    default:
        $start = date('Y-m-d', strtotime('monday this week'));
        $end   = date('Y-m-d', strtotime('sunday this week'));
        $rangeLabel = date('M j', strtotime($start)) . ' – ' . date('M j, Y', strtotime($end));
        break;
}

// ---- Attendance data (only if an attendance table exists in HR1) ------------
$records = [];

$tbl = db()->prepare('SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = ?');
$tbl->execute(['attendance']);
if ((int) $tbl->fetchColumn() > 0) {
    try {
        $stmt = db()->prepare('SELECT attendance_date, time_in, time_out, work_hours, late_minutes, overtime, status
                               FROM attendance
                               WHERE employee_id = ?
                               AND attendance_date BETWEEN ? AND ?
                               ORDER BY attendance_date DESC, time_in ASC');
        $stmt->execute([$employeeId, $start, $end]);
        $records = $stmt->fetchAll();
    } catch (Throwable $e) {
        $records = [];
    }
}

// Convert a work/overtime value (TIME '09:02:00' or decimal hours) to seconds.
$toSeconds = static function ($v): ?int {
    if ($v === null || $v === '') { return null; }
    if (preg_match('/^(\d{1,3}):([0-5]\d)(?::([0-5]\d))?$/', (string) $v, $m)) {
        return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) ($m[3] ?? 0);
    }
    return (int) round(((float) $v) * 3600);
};

// Seconds to "H:MM".
$fmtDur = static function (?int $sec): string {
    if ($sec === null) { return '—'; }
    $h  = intdiv($sec, 3600);
    $mm = str_pad((string) intdiv($sec % 3600, 60), 2, '0', STR_PAD_LEFT);
    return $h . ':' . $mm;
};

// Minutes to "Nm".
$fmtBump = static function (?int $min): string {
    return $min === null ? '—' : ((string) $min . 'm');
};

$avgWork = null; $avgLate = null; $avgOver = null;
if ($records) {
    $totWork = 0; $cntWork = 0;
    $totLate = 0; $cntLate = 0;
    $totOver = 0; $cntOver = 0;
    foreach ($records as $r) {
        $w = $toSeconds($r['work_hours'] ?? null);
        if ($w !== null) { $totWork += $w; $cntWork++; }
        $l = $r['late_minutes'] ?? null;
        if ($l !== null && $l !== '') { $totLate += (int) $l; $cntLate++; }
        $o = $toSeconds($r['overtime'] ?? null);
        if ($o !== null) { $totOver += $o; $cntOver++; }
    }
    if ($cntWork > 0) { $avgWork = (int) round($totWork / $cntWork); }
    if ($cntLate > 0) { $avgLate = (int) round($totLate / $cntLate); }
    if ($cntOver > 0) { $avgOver = (int) round($totOver / $cntOver); }
}

$statusBadge = static function ($status): string {
    $map = [
        'Present'   => 'badge-success',
        'Late'      => 'badge-warning',
        'Absent'    => 'badge-danger',
        'On Leave'  => 'badge-info',
        'Rest Day'  => 'badge-secondary',
    ];
    $key = trim((string) $status);
    $cls = $map[$key] ?? 'badge-secondary';
    return '<span class="badge ' . $cls . '">' . e($key !== '' ? $key : '—') . '</span>';
};

$fmtClock = static function ($v): string {
    if ($v === null || $v === '') { return '—'; }
    return date('g:i A', strtotime('1970-01-01 ' . substr((string) $v, 0, 8)));
};
?>

<a class="ess-back" href="<?= BASE_URL ?>/index.php"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back</a>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">My Attendance</h1>
        <p class="page-subtitle">View your attendance records, working hours, lateness, and overtime.</p>
    </div>
</div>

<form class="att-toolbar inline-form compact fade-in-up" method="get" action="<?= BASE_URL ?>/modules/employee/attendance.php">
    <div class="form-group">
        <select name="period" aria-label="Attendance period" onchange="this.form.submit()">
            <?php foreach ($periods as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $key === $period ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <span class="att-range">
        <?= e($periods[$period]) ?>
        <small><?= e($rangeLabel) ?></small>
    </span>
</form>

<section class="stats-grid stats-grid-3 fade-in-up">
    <div class="kpi-card">
        <span class="kpi-value"><?= $fmtDur($avgWork) ?></span>
        <span class="kpi-label">Average Work Hours</span>
    </div>
    <div class="kpi-card">
        <span class="kpi-value"><?= $fmtBump($avgLate) ?></span>
        <span class="kpi-label">Average Late</span>
    </div>
    <div class="kpi-card">
        <span class="kpi-value"><?= $fmtDur($avgOver) ?></span>
        <span class="kpi-label">Overtime</span>
    </div>
</section>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Attendance Records</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Work Hours</th>
                    <th>Late</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                <tr>
                    <td class="empty" colspan="6">
                        <strong>No attendance records</strong><br>
                        There are no attendance records available for the selected period.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($records as $r): ?>
                    <tr>
                        <td><strong><?= e(date('M j', strtotime((string) $r['attendance_date']))) ?></strong></td>
                        <td><?= $fmtClock($r['time_in'] ?? null) ?></td>
                        <td><?= $fmtClock($r['time_out'] ?? null) ?></td>
                        <td><?= $fmtDur($toSeconds($r['work_hours'] ?? null)) ?></td>
                        <td><?= $fmtBump(($r['late_minutes'] ?? null) !== null && ($r['late_minutes'] ?? '') !== '' ? (int) $r['late_minutes'] : null) ?></td>
                        <td><?= $statusBadge($r['status'] ?? null) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>