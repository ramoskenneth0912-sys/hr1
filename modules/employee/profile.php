<?php
/**
 * MY PROFILE — personal & employment information.
 * Left column: profile summary, quick stats, account security.
 * Right column: tabbed information card (Personal / Employment / Account).
 * Self-service actions live here too: employees keep their own contact
 * details (Edit Profile) and password (Change Password). HR-controlled
 * details are not editable. Leave requests live in leave.php;
 * documents in documents.php; salary in salary.php.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_log.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
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

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if ($action === 'edit_profile') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
        $maritalStatus = trim((string) ($_POST['marital_status'] ?? ''));

        $allowedMarital = ['single', 'married', 'widowed', 'separated'];
        $errors = [];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if ($phone !== '' && mb_strlen($phone) > 30) {
            $errors[] = 'Phone number is too long (max 30 characters).';
        }
        if ($err = validateDateOfBirth($birthDate)) {
            $errors[] = 'Birthdate: ' . $err;
        }
        if ($maritalStatus !== '' && !in_array($maritalStatus, $allowedMarital, true)) {
            $errors[] = 'Invalid marital status.';
        }

        if (!$errors) {
            $s = db()->prepare('SELECT COUNT(*) FROM employees WHERE email = ? AND id <> ?');
            $s->execute([$email, $employeeId]);
            if ((int) $s->fetchColumn() > 0) {
                $errors[] = 'That email address is already used by another employee.';
            }
            $su = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
            $su->execute([$email, $user['id']]);
            if ((int) $su->fetchColumn() > 0) {
                $errors[] = 'That email address is already used by another account.';
            }
        }

        if ($errors) {
            flash('danger', implode(' ', $errors));
            redirect(BASE_URL . '/modules/employee/profile.php#personal');
        }

        db()->beginTransaction();
        try {
            db()->prepare('UPDATE employees SET email = ?, phone = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$email, $phone !== '' ? $phone : null, $employeeId]);
            db()->prepare('UPDATE users SET email = ?, phone = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$email, $phone !== '' ? $phone : null, $user['id']]);

            db()->prepare(
                'INSERT INTO ess_profiles
                    (employee_id, birth_date, marital_status)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE
                    birth_date = VALUES(birth_date),
                    marital_status = VALUES(marital_status)'
            )->execute([
                $employeeId,
                $birthDate !== '' ? $birthDate : null,
                $maritalStatus !== '' ? $maritalStatus : null,
            ]);

            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            flash('danger', 'Unable to save your profile. Please try again.');
            redirect(BASE_URL . '/modules/employee/profile.php#personal');
        }

        securityLog('profile_updated', "employee_id={$employeeId}", (int) $user['id']);
        flash('success', 'Your profile has been updated.');
        redirect(BASE_URL . '/modules/employee/profile.php#personal');
    }

    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $pwKey = 'pwchange_' . (int) $user['id'];

        if (!webRateLimit($pwKey, 5, 900)) {
            flash('danger', 'Too many failed password attempts. Please try again in 15 minutes.');
            redirect(BASE_URL . '/modules/employee/profile.php#account');
        }

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            webRateLimitRecord($pwKey);
            flash('danger', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            flash('danger', 'New password must be at least 8 characters and contain letters and numbers.');
        } elseif ($new !== $confirm) {
            flash('danger', 'New password and confirmation do not match.');
        } elseif (password_verify($new, $row['password_hash'])) {
            flash('warning', 'New password must be different from your current password.');
        } else {
            $changedAt = date('Y-m-d H:i:s');
            db()->prepare('UPDATE users SET password_hash = ?, password_changed_at = ?, updated_at = NOW() WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $changedAt, $user['id']]);

            // Keep the current session valid while all other sessions are
            // invalidated by the existing stale-session check in auth.php.
            $_SESSION['pw_changed'] = $changedAt;
            session_regenerate_id(true);
            csrf_rotate();
            webRateLimitReset($pwKey);
            securityLog('password_changed', "self-service employee_id={$employeeId}", (int) $user['id']);
            flash('success', 'Password changed successfully.');
        }
        redirect(BASE_URL . '/modules/employee/profile.php#account');
    }
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

        </aside>

        <!-- ===================== RIGHT COLUMN ===================== -->
        <section class="panel pf-main">
            <div class="tab-bar" role="tablist" aria-label="Profile information">
                <button type="button" class="tab-link active" data-pf-tab="personal" role="tab" aria-selected="true">Personal Information</button>
                <button type="button" class="tab-link" data-pf-tab="employment" role="tab" aria-selected="false">Employment Information</button>
                <button type="button" class="tab-link" data-pf-tab="account" role="tab" aria-selected="false">Account Settings</button>
            </div>

            <!-- Personal information -->
            <div class="pf-tab-panel" data-pf-panel="personal" role="tabpanel" id="personal">
                <div class="pf-panel-head">
                    <h2>Personal Details</h2>
                    <button type="button" class="btn btn-sm btn-outline" id="pfEditProfileBtn">Edit Profile</button>
                </div>

                <div class="detail-grid" id="pfEditView">
                    <div class="detail-item"><label>Username</label><span><?= e($user['username'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Email Address</label><span><?= e($employee['email'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>First Name</label><span><?= e($employee['first_name'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Last Name</label><span><?= e($employee['last_name'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Phone Number</label><span><?= e($employee['phone'] ?: '—') ?></span></div>
                    <div class="detail-item"><label>Birthdate</label><span><?= formatDate($profile['birth_date'] ?? null) ?></span></div>
                    <div class="detail-item"><label>Employee Number</label><span><?= e($employee['employee_no'] ?? '—') ?></span></div>
                </div>

                <form method="post" class="form-panel compact-form" id="pfEditForm" hidden>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit_profile">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="pf_email">Email Address *</label>
                            <input type="email" id="pf_email" name="email" required value="<?= e($employee['email'] ?? '') ?>" autocomplete="email">
                        </div>
                        <div class="form-group">
                            <label for="pf_phone">Phone Number</label>
                            <input type="text" id="pf_phone" name="phone" maxlength="30" value="<?= e($employee['phone'] ?? '') ?>" placeholder="e.g. +63 917 000 0000" autocomplete="tel">
                        </div>
                        <div class="form-group">
                            <label for="pf_birth_date">Birthdate</label>
                            <input type="date" id="pf_birth_date" name="birth_date" value="<?= e($profile['birth_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label for="pf_marital_status">Marital Status</label>
                            <select id="pf_marital_status" name="marital_status">
                                <option value="">—</option>
                                <?php foreach (['single', 'married', 'widowed', 'separated'] as $ms): ?>
                                <option value="<?= $ms ?>" <?= ($profile['marital_status'] ?? '') === $ms ? 'selected' : '' ?>><?= e(ucfirst($ms)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Profile</button>
                        <button type="button" class="btn btn-outline" id="pfEditCancelBtn">Cancel</button>
                    </div>
                </form>
            </div>

            <!-- Employment information -->
            <div class="pf-tab-panel" data-pf-panel="employment" role="tabpanel" id="employment" hidden>
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
            <div class="pf-tab-panel" data-pf-panel="account" role="tabpanel" id="account" hidden>
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

                <div class="pf-security-section" id="pfChangePw">
                    <h3 class="pf-card-title">Change Password</h3>
                    <p class="pf-note" style="margin-top:-.35rem;">Password policy: at least 8 characters with letters and numbers.</p>
                    <form method="post" class="form-panel compact-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label for="pf_current_password">Current Password *</label>
                                <input type="password" id="pf_current_password" name="current_password" required autocomplete="current-password">
                            </div>
                            <div class="form-group">
                                <label for="pf_new_password">New Password *</label>
                                <input type="password" id="pf_new_password" name="new_password" required minlength="8" autocomplete="new-password">
                            </div>
                            <div class="form-group">
                                <label for="pf_confirm_password">Confirm New Password *</label>
                                <input type="password" id="pf_confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Update Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

    </div>
</div>

<style>
    /* Change Password section divider (reuses existing tokens). */
    .pf-security-section {
        border-top: 1px solid var(--border);
        margin-top: 1.5rem;
        padding-top: 1.25rem;
    }
    .pf-security-section .pf-card-title { margin-top: 0; }
</style>

<script>
(function () {
    var tabs = document.querySelectorAll('[data-pf-tab]');
    var panels = document.querySelectorAll('[data-pf-panel]');

    function activate(name) {
        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-pf-tab') === name;
            tab.classList.toggle('active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panels.forEach(function (p) {
            p.hidden = p.getAttribute('data-pf-panel') !== name;
        });
    }

    function setEdit(open) {
        var view = document.getElementById('pfEditView');
        var form = document.getElementById('pfEditForm');
        var btn = document.getElementById('pfEditProfileBtn');
        if (!view || !form) return;
        view.hidden = open;
        form.hidden = !open;
        if (btn) btn.textContent = open ? 'View Details' : 'Edit Profile';
        if (open) {
            var first = form.querySelector('input:not([type="hidden"]), select, textarea');
            if (first) first.focus();
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.getAttribute('data-pf-tab');
            activate(target);
            var open = tab.getAttribute('data-pf-open');
            if (open === 'edit') setEdit(true);
            if (open === 'password') {
                var pw = document.getElementById('pfChangePw');
                if (pw) pw.scrollIntoView();
            }
        });
    });

    var editBtn = document.getElementById('pfEditProfileBtn');
    if (editBtn) editBtn.addEventListener('click', function () {
        var form = document.getElementById('pfEditForm');
        setEdit(!form || form.hidden);
    });

    var cancelBtn = document.getElementById('pfEditCancelBtn');
    if (cancelBtn) cancelBtn.addEventListener('click', function () { setEdit(false); });

    // Deep-link support: load the tab named in the URL hash after revealing it.
    var hash = window.location.hash.slice(1);
    if (hash === 'employment' || hash === 'account') {
        activate(hash);
        var target = document.getElementById(hash);
        if (target) window.requestAnimationFrame(function () { target.scrollIntoView(); });
    }
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>