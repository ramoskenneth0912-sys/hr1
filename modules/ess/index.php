<?php
require_once __DIR__ . '/../../includes/auth.php';
requireNotApplicant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'leave_request') {
        essAssertOwnOrManager((int) $_POST['employee_id']);
        db()->prepare(
            'INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason)
             VALUES (?,?,?,?,?)'
        )->execute([
            (int) $_POST['employee_id'],
            $_POST['leave_type'],
            $_POST['start_date'],
            $_POST['end_date'],
            trim($_POST['reason'] ?? ''),
        ]);
        flash('success', 'Leave request submitted.');
        redirect(BASE_URL . '/modules/ess/index.php');
    }

    if ($action === 'approve_leave') {
        if (!isHRorManager()) {
            flash('danger', 'You do not have permission to approve leave requests.');
            redirect(BASE_URL . '/modules/ess/index.php');
        }
        db()->prepare(
            'UPDATE leave_requests SET status=?, approved_by=? WHERE id=?'
        )->execute([
            $_POST['status'],
            trim($_POST['approved_by'] ?? 'HR Admin'),
            (int) $_POST['leave_id'],
        ]);

        // Notify the employee's account of the leave decision.
        $lrStmt = db()->prepare('SELECT lr.*, u.id AS user_id FROM leave_requests lr
             LEFT JOIN users u ON u.employee_id = lr.employee_id
             WHERE lr.id = ?');
        $lrStmt->execute([(int) $_POST['leave_id']]);
        if ($lr = $lrStmt->fetch()) {
            notifyUser(
                (int) $lr['user_id'],
                'Leave request ' . $_POST['status'],
                'Your ' . $lr['leave_type'] . ' leave request (' . formatDate($lr['start_date'])
                    . ' — ' . formatDate($lr['end_date']) . ') was ' . $_POST['status'] . '.',
                BASE_URL . '/modules/employee/leave.php'
            );
        }

        flash('success', 'Leave request updated.');
        redirect(BASE_URL . '/modules/ess/index.php');
    }

    if ($action === 'update_profile') {
        $employeeId = (int) $_POST['employee_id'];
        essAssertOwnOrManager($employeeId);
        $exists = db()->prepare('SELECT id FROM ess_profiles WHERE employee_id = ?');
        $exists->execute([$employeeId]);

        if ($exists->fetch()) {
            db()->prepare(
                'UPDATE ess_profiles SET emergency_contact_name=?, emergency_contact_phone=?,
                 address=?, birth_date=?, marital_status=? WHERE employee_id=?'
            )->execute([
                trim($_POST['emergency_contact_name'] ?? ''),
                trim($_POST['emergency_contact_phone'] ?? ''),
                trim($_POST['address'] ?? ''),
                $_POST['birth_date'] ?: null,
                $_POST['marital_status'],
                $employeeId,
            ]);
        } else {
            db()->prepare(
                'INSERT INTO ess_profiles (employee_id, emergency_contact_name, emergency_contact_phone,
                 address, birth_date, marital_status) VALUES (?,?,?,?,?,?)'
            )->execute([
                $employeeId,
                trim($_POST['emergency_contact_name'] ?? ''),
                trim($_POST['emergency_contact_phone'] ?? ''),
                trim($_POST['address'] ?? ''),
                $_POST['birth_date'] ?: null,
                $_POST['marital_status'],
            ]);
        }
        flash('success', 'Profile updated.');
        redirect(BASE_URL . '/modules/ess/index.php?employee_id=' . $employeeId);
    }
}

$pageTitle = 'Employee Self Service';
$currentModule = 'ess';
require_once __DIR__ . '/../../includes/header.php';

$employees = getEmployees();
$selectedEmployeeId = (int) ($_GET['employee_id'] ?? ($employees[0]['id'] ?? 0));

$leaveRequests = db()->query(
    'SELECT lr.*, e.employee_no, e.first_name, e.last_name
     FROM leave_requests lr
     JOIN employees e ON lr.employee_id = e.id
     ORDER BY lr.created_at DESC'
)->fetchAll();

$profile = null;
if ($selectedEmployeeId) {
    $stmt = db()->prepare('SELECT * FROM ess_profiles WHERE employee_id = ?');
    $stmt->execute([$selectedEmployeeId]);
    $profile = $stmt->fetch() ?: [];
}
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Employee Self Service</h1>
        <p class="page-subtitle">Module 5 — Leave requests and employee profiles</p>
    </div>
</div>

<div class="two-col">
    <section class="panel fade-in-up" style="animation-delay:.1s">
        <h2>Submit Leave Request</h2>
        <form method="post" class="form-panel compact-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="leave_request">
            <div class="form-group">
                <label for="employee_id">Employee</label>
                <select id="employee_id" name="employee_id" required>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int) $emp['id'] ?>"><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="leave_type">Leave Type</label>
                <select id="leave_type" name="leave_type" required>
                    <?php foreach (['vacation','sick','emergency','maternity','paternity','unpaid'] as $t): ?>
                    <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="start_date" required>
            </div>
            <div class="form-group">
                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="end_date" required>
            </div>
            <div class="form-group">
                <label for="reason">Reason</label>
                <textarea id="reason" name="reason" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit Request</button>
        </form>
    </section>

    <section class="panel fade-in-up" style="animation-delay:.2s">
        <h2>My Profile</h2>
        <form method="post" class="form-panel compact-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <div class="form-group">
                <label for="profile_employee_id">Employee</label>
                <select id="profile_employee_id" name="employee_id" onchange="location.href='?employee_id='+this.value">
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int) $emp['id'] ?>" <?= $selectedEmployeeId === (int) $emp['id'] ? 'selected' : '' ?>>
                        <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="emergency_contact_name">Emergency Contact</label>
                <input type="text" id="emergency_contact_name" name="emergency_contact_name" value="<?= e($profile['emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="emergency_contact_phone">Emergency Phone</label>
                <input type="text" id="emergency_contact_phone" name="emergency_contact_phone" value="<?= e($profile['emergency_contact_phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="birth_date">Birth Date</label>
                <input type="date" id="birth_date" name="birth_date" value="<?= e($profile['birth_date'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="marital_status">Marital Status</label>
                <select id="marital_status" name="marital_status">
                    <?php foreach (['single','married','widowed','separated'] as $m): ?>
                    <option value="<?= $m ?>" <?= ($profile['marital_status'] ?? 'single') === $m ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="2"><?= e($profile['address'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Save Profile</button>
        </form>
    </section>
</div>

<section class="panel fade-in-up" style="animation-delay:.3s">
    <h2>Leave Requests</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>From</th>
                <th>To</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($leaveRequests)): ?>
            <tr><td colspan="6" class="empty">No leave requests.</td></tr>
            <?php else: foreach ($leaveRequests as $lr): ?>
            <tr>
                <td><?= e($lr['first_name'] . ' ' . $lr['last_name']) ?></td>
                <td><?= e(ucfirst($lr['leave_type'])) ?></td>
                <td><?= formatDate($lr['start_date']) ?></td>
                <td><?= formatDate($lr['end_date']) ?></td>
                <td><?= statusBadge($lr['status']) ?></td>
                <td>
                    <?php if ($lr['status'] === 'pending'): ?>
                    <form method="post" class="inline-form compact">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve_leave">
                        <input type="hidden" name="leave_id" value="<?= (int) $lr['id'] ?>">
                        <select name="status">
                            <option value="approved">Approve</option>
                            <option value="rejected">Reject</option>
                        </select>
                        <button type="submit" class="btn btn-sm">Update</button>
                    </form>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
