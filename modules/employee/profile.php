<?php
/**
 * MY PROFILE — personal & employment information (read-only view).
 * Left column: profile summary, quick stats, account security.
 * Right column: tabbed information card (Personal / Employment / Account).
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

// Last login comes from the existing security log (authoritative, not invented).
$last = db()->prepare(
    "SELECT MAX(created_at) AS last_login FROM security_log
     WHERE user_id = ? AND event_type = 'login_success'"
);
$last->execute([$user['id']]);
$lastLogin = $last->fetch()['last_login'] ?? null;

// Years of service: prefer the stored tenure_years, fall back to hire date.
$yearsOfService = null;
if (isset($employee['tenure_years']) && $employee['tenure_years'] !== null && $employee['tenure_years'] !== '') {
    $yearsOfService = (float) $employee['tenure_years'];
} elseif (!empty($employee['hire_date'])) {
    $yearsOfService = ((int) floor(time() / 86400) - (int) floor(strtotime($employee['hire_date']) / 86400)) / 365.25;
}
$yearsOfServiceLabel = $yearsOfService !== null
    ? (round($yearsOfService * 10) / 10) . ' yrs'
    : '—';

// Profile completion = share of the real, employee-editable profile fields
// that are actually filled in the existing record (no fabricated numbers).
$completionFields = [
    !empty($profile['photo_path']),
    !empty($employee['phone']),
    !empty($profile['birth_date']),
    !empty($profile['address']),
    !empty($profile['marital_status']),
    !empty($profile['emergency_contact_name']),
    !empty($profile['emergency_contact_phone']),
    !empty($profile['education']),
    !empty($profile['skills']),
    !empty($profile['work_experience']),
];
$completionTotal = count($completionFields);
$completionPct = $completionTotal > 0 ? (int) round((count(array_filter($completionFields)) / $completionTotal) * 100) : 0;

$fullName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
$initials = mb_strtoupper(mb_substr(trim($employee['first_name'] ?? 'E'), 0, 1))
    . mb_strtoupper(mb_substr(trim($employee['last_name'] ?? ''), 0, 1));

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="profile-page">
    <div class="page-header fade-in-up">
        <div>
            <h1 class="page-title">My Profile</h1>
            <p class="page-subtitle">Personal and employment information</p>
        </div>
    </div>

    <div class="profile-layout fade-in-up">

        <!-- ===================== LEFT COLUMN ===================== -->
        <aside class="profile-side">

            <!-- Profile summary -->
            <section class="panel pf-summary-card">
                <div class="pf-summary">
                    <?php if (!empty($profile['photo_path'])): ?>
                        <img class="pf-avatar" src="<?= BASE_URL ?>/<?= e($profile['photo_path']) ?>" alt="Profile photo">
                    <?php else: ?>
                        <span class="pf-avatar" role="img" aria-label="Profile initials"><?= e($initials ?: 'E') ?></span>
                    <?php endif; ?>

                    <h2 class="pf-name"><?= e($fullName ?: '—') ?></h2>
                    <p class="pf-employee-no">Employee No: <?= e($employee['employee_no'] ?? '—') ?></p>

                    <div class="pf-badges">
                        <?php if (!empty($employee['job_title'])): ?>
                            <span class="badge badge-secondary"><?= e($employee['job_title']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($employee['department_name'])): ?>
                            <span class="badge badge-secondary"><?= e($employee['department_name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="pf-status-badge"><?= statusBadge($employee['status'] ?? 'active') ?></div>
                </div>

                <div class="pf-completion">
                    <div class="pf-completion-top">
                        <span>Profile completion</span>
                        <strong><?= (int) $completionPct ?>%</strong>
                    </div>
                    <div class="progress-bar" role="progressbar" aria-valuenow="<?= (int) $completionPct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Profile completion">
                        <div class="progress-fill" style="width: <?= (int) $completionPct ?>%"></div>
                    </div>
                </div>
            </section>

            <!-- Quick stats -->
            <section class="panel pf-quick-stats">
                <h3 class="pf-card-title">Quick Stats</h3>
                <ul class="pf-stats">
                    <li class="pf-stat">
                        <span class="pf-stat-icon" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </span>
                        <span class="pf-stat-body">
                            <span class="pf-stat-label">Years of Service</span>
                            <strong><?= e($yearsOfServiceLabel) ?></strong>
                        </span>
                    </li>
                    <li class="pf-stat">
                        <span class="pf-stat-icon" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </span>
                        <span class="pf-stat-body">
                            <span class="pf-stat-label">Status</span>
                            <?= statusBadge($employee['status'] ?? 'active') ?>
                        </span>
                    </li>
                    <li class="pf-stat">
                        <span class="pf-stat-icon" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                        </span>
                        <span class="pf-stat-body">
                            <span class="pf-stat-label">Role</span>
                            <strong><?= e($employee['job_title'] ?? '—') ?></strong>
                        </span>
                    </li>
                    <li class="pf-stat">
                        <span class="pf-stat-icon" aria-hidden="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </span>
                        <span class="pf-stat-body">
                            <span class="pf-stat-label">Last Login</span>
                            <strong><?= e($lastLogin ? date('M d, Y g:i A', strtotime($lastLogin)) : '—') ?></strong>
                        </span>
                    </li>
                </ul>
            </section>

            <!-- Account security -->
            <section class="panel pf-account-security">
                <h3 class="pf-card-title">Account Security</h3>
                <div class="pf-actions">
                    <form method="post" action="<?= BASE_URL ?>/auth/logout.php" class="pf-signout-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-danger-solid btn-sm" data-confirm-logout>Sign Out</button>
                    </form>
                </div>
            </section>

        </aside>

        <!-- ===================== RIGHT COLUMN ===================== -->
        <section class="panel pf-main">
            <div class="tab-bar" role="tablist" aria-label="Profile information">
                <button type="button" class="tab-link active" data-pf-tab="personal" role="tab" aria-selected="true">Personal Information</button>
                <button type="button" class="tab-link" data-pf-tab="employment" role="tab" aria-selected="false">Employment Information</button>
                <button type="button" class="tab-link" data-pf-tab="account" role="tab" aria-selected="false">Account Settings</button>
            </div>

            <!-- Personal information -->
            <div class="pf-tab-panel" data-pf-panel="personal" role="tabpanel">
                <div class="pf-panel-head">
                    <h2>Personal Details</h2>
                </div>
                <div class="detail-grid">
                    <div class="detail-item"><label>Username</label><span><?= e($user['username'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Email Address</label><span><?= e($employee['email'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>First Name</label><span><?= e($employee['first_name'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Last Name</label><span><?= e($employee['last_name'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Phone Number</label><span><?= e($employee['phone'] ?: '—') ?></span></div>
                    <div class="detail-item"><label>Birthdate</label><span><?= formatDate($profile['birth_date'] ?? null) ?></span></div>
                    <div class="detail-item"><label>Employee Number</label><span><?= e($employee['employee_no'] ?? '—') ?></span></div>
                </div>
            </div>

            <!-- Employment information -->
            <div class="pf-tab-panel" data-pf-panel="employment" role="tabpanel" hidden>
                <div class="pf-panel-head">
                    <h2>Employment Details</h2>
                </div>
                <div class="detail-grid">
                    <div class="detail-item"><label>Employee No.</label><span><?= e($employee['employee_no'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Current Role</label><span><?= e($employee['job_title'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Department</label><span><?= e($employee['department_name'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Branch</label><span><?= e($employee['branch'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Employment Type</label><span><?= e(ucfirst(str_replace('_', ' ', $employee['employment_type'] ?? '—'))) ?></span></div>
                    <div class="detail-item"><label>Years of Service</label><span><?= e($yearsOfServiceLabel) ?></span></div>
                    <div class="detail-item"><label>Hire Date</label><span><?= formatDate($employee['hire_date'] ?? null) ?></span></div>
                    <div class="detail-item"><label>Education Level</label><span><?= e($employee['education_level'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Gender</label><span><?= e($employee['gender'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Age</label><span><?= e(isset($employee['age']) && $employee['age'] !== null ? (string) $employee['age'] : '—') ?></span></div>
                    <div class="detail-item"><label>Status</label><span><?= statusBadge($employee['status'] ?? 'active') ?></span></div>
                </div>
                <p class="pf-note">Employment status, department and job details can only be changed by HR.</p>
            </div>

            <!-- Account settings -->
            <div class="pf-tab-panel" data-pf-panel="account" role="tabpanel" hidden>
                <div class="pf-panel-head">
                    <h2>Account</h2>
                </div>
                <div class="detail-grid">
                    <div class="detail-item"><label>Username</label><span><?= e($user['username'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Role</label><span><?= e(ucfirst($user['role'] ?? 'employee')) ?></span></div>
                    <div class="detail-item"><label>Email Address</label><span><?= e($user['email'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Employee No.</label><span><?= e($employee['employee_no'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Password Last Changed</label><span><?= formatDate($user['password_changed_at'] ?? null) ?></span></div>
                </div>
                <div class="pf-account-actions">
                    <form method="post" action="<?= BASE_URL ?>/auth/logout.php" class="pf-signout-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-danger-solid btn-sm" data-confirm-logout>Sign Out</button>
                    </form>
                </div>
                <p class="pf-note">To sign out on this device, use the Sign Out button or the Logout button in the header.</p>
            </div>
        </section>

    </div>
</div>

<script>
(function () {
    var tabs = document.querySelectorAll('[data-pf-tab]');
    var panels = document.querySelectorAll('[data-pf-panel]');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.getAttribute('data-pf-tab');
            tabs.forEach(function (t) {
                t.classList.toggle('active', t === tab);
                t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
            });
            panels.forEach(function (p) {
                p.hidden = p.getAttribute('data-pf-panel') !== target;
            });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>