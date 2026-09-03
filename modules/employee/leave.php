<?php
/**
 * LEAVE REQUESTS — leave balance summary, submission and history ONLY.
 * Moved out of the old My Account page so each section has one home.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $user = getCurrentUser();
    $employeeId = $user['employee_id'] ?? null;

    if (!$employeeId) {
        flash('danger', 'No employee profile linked to your account.');
        redirect(BASE_URL . '/modules/employee/leave.php');
    }

    $leaveType = trim((string) ($_POST['leave_type'] ?? ''));
    $startDate = trim((string) ($_POST['start_date'] ?? ''));
    $endDate = trim((string) ($_POST['end_date'] ?? ''));

    $leaveErrors = [];
    if ($leaveType === '') {
        $leaveErrors[] = 'Leave type is required.';
    }
    if ($startDate === '') {
        $leaveErrors[] = 'Start date is required.';
    }
    if ($endDate === '') {
        $leaveErrors[] = 'End date is required.';
    }
    if (!$leaveErrors) {
        $leaveErrors = validateDateRange($startDate, $endDate, true);
    }
    if ($leaveErrors) {
        flash('danger', implode(' ', $leaveErrors));
        redirect(BASE_URL . '/modules/employee/leave.php');
    }

    db()->prepare(
        'INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason)
         VALUES (?,?,?,?,?)'
    )->execute([
        $employeeId,
        $leaveType,
        $startDate,
        $endDate,
        trim($_POST['reason'] ?? ''),
    ]);
    flash('success', 'Leave request submitted.');
    redirect(BASE_URL . '/modules/employee/leave.php');
}

$pageTitle = 'Leave Requests';
$currentModule = 'leave-requests';
$bodyClass = 'page-dashboard';
$user = getCurrentUser();
$employeeId = $user['employee_id'] ?? null;

if (!$employeeId) {
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="page-header fade-in-up">
        <div>
            <h1 class="page-title">Leave Requests</h1>
            <p class="page-subtitle">Employee Self-Service</p>
        </div>
    </div>
    <div class="alert alert-warning">No employee profile linked to your account. Please contact HR.</div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$leaveStmt = db()->prepare(
    'SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC'
);
$leaveStmt->execute([$employeeId]);
$leaveRequests = $leaveStmt->fetchAll();

$pendingCount = count(array_filter($leaveRequests, fn ($r) => ($r['status'] ?? '') === 'pending'));
$approvedCount = count(array_filter($leaveRequests, fn ($r) => ($r['status'] ?? '') === 'approved'));

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Leave Requests</h1>
        <p class="page-subtitle">Submit and track your leave requests</p>
    </div>
</div>

<div class="stats-grid">
    <div class="kpi-card fade-in-up" style="animation-delay:.1s">
        <span class="kpi-value"><?= count($leaveRequests) ?></span>
        <span class="kpi-label">Total Requests</span>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.2s">
        <span class="kpi-value"><?= $pendingCount ?></span>
        <span class="kpi-label">Pending</span>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.3s">
        <span class="kpi-value"><?= $approvedCount ?></span>
        <span class="kpi-label">Approved</span>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.2s">
    <h2>My Leave Requests</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Leave Type</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Status</th>
                <th>Reason</th>
                <th>Submitted</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($leaveRequests)): ?>
            <tr><td colspan="6" class="empty">No leave requests yet.</td></tr>
            <?php else: foreach ($leaveRequests as $lr): ?>
            <tr>
                <td><?= e(ucfirst($lr['leave_type'])) ?></td>
                <td><?= formatDate($lr['start_date']) ?></td>
                <td><?= formatDate($lr['end_date']) ?></td>
                <td><?= statusBadge($lr['status']) ?></td>
                <td><?= e($lr['reason'] ?? '—') ?></td>
                <td><?= formatDate($lr['created_at'] ?? null) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<section class="panel fade-in-up" style="animation-delay:.3s">
    <h2>Submit Leave Request</h2>
    <form method="post" class="form-panel compact-form">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-group">
                <label for="leave_type">Leave Type *</label>
                <select id="leave_type" name="leave_type" required>
                    <?php foreach (['vacation','sick','emergency','maternity','paternity','unpaid'] as $t): ?>
                    <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="start_date">Start Date *</label>
                <input type="date" id="start_date" name="start_date" required min="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label for="end_date">End Date *</label>
                <input type="date" id="end_date" name="end_date" required min="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group full-width">
                <label for="reason">Reason</label>
                <textarea id="reason" name="reason" rows="2"></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Submit Request</button>
        </div>
    </form>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
