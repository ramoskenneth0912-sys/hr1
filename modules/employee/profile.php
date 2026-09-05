<?php
/**
 * MY PROFILE — personal & employment information (read-only view).
 * Editing happens in Settings. Leave requests live in leave.php;
 * documents in documents.php; salary in salary.php.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

$pageTitle = 'My Profile';
$currentModule = 'my-profile';
$bodyClass = 'page-dashboard';
$user = getCurrentUser();
$employeeId = $user['employee_id'] ?? null;

if (!$employeeId) {
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="page-header fade-in-up">
        <div>
            <h1 class="page-title">My Profile</h1>
            <p class="page-subtitle">Employee Self-Service</p>
        </div>
    </div>
    <div class="alert alert-warning">No employee profile linked to your account. Please contact HR.</div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$emp = db()->prepare(
    'SELECT e.*, d.name AS department_name
     FROM employees e
     LEFT JOIN departments d ON e.department_id = d.id
     WHERE e.id = ?'
);
$emp->execute([$employeeId]);
$employee = $emp->fetch();

$stmt = db()->prepare('SELECT * FROM ess_profiles WHERE employee_id = ?');
$stmt->execute([$employeeId]);
$profile = $stmt->fetch() ?: [];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">My Profile</h1>
        <p class="page-subtitle">Personal and employment information</p>
    </div>
    <div class="btn-group">
        <a href="<?= BASE_URL ?>/modules/employee/settings.php" class="btn btn-primary">Edit Profile</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Personal Information</h2>
    <div class="profile-photo-row">
        <?php if (!empty($profile['photo_path'])): ?>
        <img class="profile-photo" src="<?= BASE_URL ?>/<?= e($profile['photo_path']) ?>" alt="Profile photo">
        <?php else: ?>
        <span class="profile-photo profile-photo-empty"><?= e(mb_strtoupper(mb_substr($employee['first_name'] ?? 'E', 0, 1))) ?></span>
        <?php endif; ?>
        <div class="detail-grid" style="flex:1;">
            <div class="detail-item"><label>Full Name</label><span><?= e(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')) ?></span></div>
            <div class="detail-item"><label>Email</label><span><?= e($employee['email'] ?? '—') ?></span></div>
            <div class="detail-item"><label>Phone</label><span><?= e($employee['phone'] ?: '—') ?></span></div>
            <div class="detail-item"><label>Address</label><span><?= nl2br(e($profile['address'] ?? '')) ?: '—' ?></span></div>
            <div class="detail-item"><label>Birth Date</label><span><?= formatDate($profile['birth_date'] ?? null) ?></span></div>
            <div class="detail-item"><label>Marital Status</label><span><?= e(ucfirst($profile['marital_status'] ?? '—')) ?></span></div>
            <div class="detail-item"><label>Emergency Contact</label><span><?= e($profile['emergency_contact_name'] ?? '—') ?></span></div>
            <div class="detail-item"><label>Emergency Phone</label><span><?= e($profile['emergency_contact_phone'] ?? '—') ?></span></div>
        </div>
    </div>
</section>

<section class="panel fade-in-up" style="animation-delay:.15s">
    <h2>Employment Information</h2>
    <div class="detail-grid">
        <div class="detail-item"><label>Employee No.</label><span><?= e($employee['employee_no'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Current Role</label><span><?= e($employee['job_title'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Branch</label><span><?= e($employee['branch'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Department</label><span><?= e($employee['department_name'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Education Level</label><span><?= e($employee['education_level'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Age</label><span><?= e(isset($employee['age']) && $employee['age'] !== null ? (string) $employee['age'] : '—') ?></span></div>
        <div class="detail-item"><label>Gender</label><span><?= e($employee['gender'] ?? '—') ?></span></div>
        <div class="detail-item"><label>Employment Type</label><span><?= e(ucfirst(str_replace('_', ' ', $employee['employment_type'] ?? '—'))) ?></span></div>
        <div class="detail-item"><label>Hire Date</label><span><?= formatDate($employee['hire_date'] ?? null) ?></span></div>
        <div class="detail-item"><label>Status</label><span><?= statusBadge($employee['status'] ?? 'active') ?></span></div>
    </div>
    <p class="panel-desc">Employment status, department and job details can only be changed by HR. Use the Edit Profile button to update your personal information in Settings.</p>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
