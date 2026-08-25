<?php
/**
 * HR/ADMIN SETTINGS — administrative configuration area.
 * Sections: Account, Security, Notifications, Users & Roles,
 * HR Preferences, Recruitment, Attendance & Leave, System (admin-only).
 *
 * Employee self-service settings intentionally live ONLY in
 * modules/employee/settings.php — employees are redirected there.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

if (isEmployee()) {
    flash('info', 'Employee settings live in your own portal.');
    redirect(BASE_URL . '/modules/employee/settings.php');
}

$user = getCurrentUser();
$uid = (int) $_SESSION['user_id'];
$isAdmin = (($user['role'] ?? '') === 'hr');

/* ---- settings helpers ------------------------------------------------- */
function get_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM system_settings') as $row) {
            $cache[$row['setting_key']] = (string) $row['setting_value'];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function save_settings(array $keys): void
{
    $stmt = db()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($keys as $k) {
        $stmt->execute([$k, trim((string) ($_POST[$k] ?? ''))]);
    }
}

$NOTIF_GROUPS = [
    'Recruitment Notifications' => [
        'new_applicant' => 'New applicant registered',
        'new_application' => 'New application submitted',
        'applicant_status_changed' => 'Applicant status changed',
        'interview_updates' => 'Interview updates',
    ],
    'HR Notifications' => [
        'leave_request_submitted' => 'Leave request submitted',
        'attendance_issues' => 'Attendance issues',
        'employee_updates' => 'Employee updates',
        'onboarding_updates' => 'Onboarding updates',
    ],
    'System Notifications' => [
        'system_announcements' => 'System announcements',
        'security_alerts' => 'Security alerts',
        'system_updates' => 'System updates',
    ],
];

/* ---- POST handlers ----------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_account') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));

        if ($username === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Please provide a valid username and email address.');
        } else {
            $s = db()->prepare('SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id <> ?');
            $s->execute([$username, $email, $uid]);
            if ((int) $s->fetchColumn() > 0) {
                flash('danger', 'That username or email is already used by another account.');
            } else {
                db()->prepare('UPDATE users SET username = ?, email = ?, phone = ? WHERE id = ?')
                    ->execute([$username, $email, $phone !== '' ? $phone : null, $uid]);
                flash('success', 'Account details updated.');
            }
        }
        redirect(BASE_URL . '/modules/settings/index.php#account');
    }

    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            flash('danger', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            flash('danger', 'New password must be at least 8 characters and contain letters and numbers.');
        } elseif ($new !== $confirm) {
            flash('danger', 'New password and confirmation do not match.');
        } elseif (password_verify($new, $row['password_hash'])) {
            flash('warning', 'New password must be different from your current password.');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            csrf_rotate();
            flash('success', 'Password changed successfully.');
        }
        redirect(BASE_URL . '/modules/settings/index.php#security');
    }

    if ($action === 'save_notifications') {
        $allKeys = array_merge(...array_values(array_map('array_keys', $NOTIF_GROUPS)));
        $stmt = db()->prepare(
            'INSERT INTO notification_preferences (user_id, pref_key, enabled) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
        );
        foreach ($allKeys as $k) {
            $stmt->execute([$uid, $k, isset($_POST[$k]) ? 1 : 0]);
        }
        flash('success', 'Notification preferences saved.');
        redirect(BASE_URL . '/modules/settings/index.php#notifications');
    }

    if ($action === 'save_hr_prefs') {
        save_settings(['company_name', 'company_subtitle', 'default_department', 'default_employment_type',
            'date_format', 'time_format', 'timezone', 'currency', 'working_days',
            'business_hours_start', 'business_hours_end']);
        flash('success', 'HR preferences saved.');
        redirect(BASE_URL . '/modules/settings/index.php#hr-preferences');
    }

    if ($action === 'save_recruitment') {
        save_settings(['rec_default_status', 'rec_stages', 'rec_notify_default']);
        flash('success', 'Recruitment settings saved.');
        redirect(BASE_URL . '/modules/settings/index.php#recruitment');
    }

    if ($action === 'save_attendance') {
        save_settings(['att_work_hours', 'att_late_threshold', 'att_overtime_enabled',
            'leave_approval_flow', 'leave_duration_unit']);
        flash('success', 'Attendance & leave settings saved.');
        redirect(BASE_URL . '/modules/settings/index.php#attendance-leave');
    }

    if ($action === 'save_system') {
        if (!$isAdmin) {
            flash('danger', 'Only Admin users may change system configuration.');
        } else {
            save_settings(['sys_name', 'sys_language', 'sys_maintenance']);
            flash('success', 'System configuration saved.');
        }
        redirect(BASE_URL . '/modules/settings/index.php#system');
    }
}

/* ---- display data ------------------------------------------------------- */
$prefs = [];
$prefsStmt = db()->prepare('SELECT pref_key, enabled FROM notification_preferences WHERE user_id = ?');
$prefsStmt->execute([$uid]);
foreach ($prefsStmt->fetchAll() as $r) {
    $prefs[$r['pref_key']] = (int) $r['enabled'];
}
$roleCounts = db()->query(
    'SELECT role, COUNT(*) AS total, COALESCE(SUM(is_active = 1),0) AS active
     FROM users GROUP BY role ORDER BY FIELD(role, "hr", "manager", "employee", "applicant")'
)->fetchAll();

$pageTitle = 'Settings';
$currentModule = 'settings';
$bodyClass = 'page-dashboard';

require_once __DIR__ . '/../../includes/header.php';

$navItems = [
    'account' => 'Account', 'security' => 'Security', 'notifications' => 'Notifications',
    'users-roles' => 'Users & Roles', 'hr-preferences' => 'HR Preferences',
    'recruitment' => 'Recruitment', 'attendance-leave' => 'Attendance & Leave',
];
if ($isAdmin) { $navItems['system'] = 'System'; }
?>
<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-subtitle">Administrative configuration for <?= e(ucfirst($user['role'])) ?> users</p>
    </div>
</div>

<div class="fade-in-up" style="display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1.25rem;">
    <?php foreach ($navItems as $anchor => $label): ?>
    <a href="#<?= e($anchor) ?>" class="btn btn-sm btn-outline"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<!-- 1. ACCOUNT -->
<section id="account" class="panel fade-in-up" style="animation-delay:.05s">
    <h2>Account Settings</h2>
    <p class="panel-desc">Your own administrator account. Employee profile data lives inside the Employee portal.</p>
    <form method="post" class="form-panel compact-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_account">
        <div class="form-grid">
            <div class="form-group">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username" required value="<?= e($user['username']) ?>">
            </div>
            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" required value="<?= e($user['email']) ?>">
            </div>
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="text" id="phone" name="phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="e.g. +63 917 000 0000">
            </div>
        </div>
        <div class="detail-grid" style="margin-top:.75rem;">
            <div class="detail-item"><label>Role</label><span><?= e(ucfirst($user['role'])) ?></span></div>
            <div class="detail-item"><label>Account Status</label><span><?= ($user['is_active'] ?? 1) ? 'Active' : 'Deactivated' ?></span></div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</section>

<!-- 2. SECURITY -->
<section id="security" class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Security</h2>
    <p class="panel-desc">Password policy: at least 8 characters with letters and numbers.</p>
    <form method="post" class="form-panel compact-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-grid">
            <div class="form-group full-width">
                <label for="current_password">Current Password *</label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="new_password">New Password *</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password *</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Update Password</button>
        </div>
    </form>
    <p class="panel-desc" style="margin-top:.5rem;">Two-factor authentication is not available in this system yet. To log out other devices, change your password (all existing sessions are then regenerated on next login). Use the Logout button in the header to end the current session.</p>
</section>

<!-- 3. NOTIFICATIONS -->
<section id="notifications" class="panel fade-in-up" style="animation-delay:.15s">
    <h2>Notification Settings</h2>
    <p class="panel-desc">Choose which system events notify your account.</p>
    <form method="post" class="form-panel compact-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_notifications">
        <?php foreach ($NOTIF_GROUPS as $groupLabel => $group): ?>
        <fieldset style="border:none; margin:0 0 .75rem; padding:0;">
            <legend style="font-weight:700; font-size:.85rem; color:var(--text-dark); padding:0; margin-bottom:.4rem;"><?= e($groupLabel) ?></legend>
            <div class="form-grid">
                <?php foreach ($group as $key => $label): ?>
                <label class="form-group" style="flex-direction:row; align-items:center; gap:.5rem;">
                    <input type="checkbox" name="<?= e($key) ?>" <?= (!isset($prefs[$key]) || $prefs[$key]) ? 'checked' : '' ?>>
                    <span style="font-size:.85rem;"><?= e($label) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php endforeach; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Notifications</button>
        </div>
    </form>
</section>

<!-- 4. USERS & ROLES -->
<section id="users-roles" class="panel fade-in-up" style="animation-delay:.2s">
    <h2>User &amp; Role Management</h2>
    <p class="panel-desc">Accounts are managed in User Management — creation, editing, activation and deactivation live there.</p>
    <div class="detail-grid">
        <?php foreach ($roleCounts as $rc): ?>
        <div class="detail-item"><label><?= e(ucfirst($rc['role'])) ?> accounts</label><span><?= (int) $rc['active'] ?> active / <?= (int) $rc['total'] ?> total</span></div>
        <?php endforeach; ?>
    </div>
    <div class="form-actions">
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-primary">Open User Management &rarr;</a>
    </div>
</section>

<!-- 5. HR PREFERENCES -->
<section id="hr-preferences" class="panel fade-in-up" style="animation-delay:.25s">
    <h2>HR / System Preferences</h2>
    <p class="panel-desc">Organization-wide values used across the HR system.</p>
    <form method="post" class="form-panel compact-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_hr_prefs">
        <div class="form-grid">
            <div class="form-group"><label>Company Name</label><input type="text" name="company_name" value="<?= e(get_setting('company_name')) ?>"></div>
            <div class="form-group"><label>Company Subtitle</label><input type="text" name="company_subtitle" value="<?= e(get_setting('company_subtitle')) ?>"></div>
            <div class="form-group"><label>Default Department</label><input type="text" name="default_department" value="<?= e(get_setting('default_department')) ?>" placeholder="e.g. Operations"></div>
            <div class="form-group"><label>Default Employment Type</label>
                <select name="default_employment_type">
                    <?php foreach (['regular','contractual','probationary','part_time','internship'] as $t): ?>
                    <option value="<?= $t ?>" <?= get_setting('default_employment_type') === $t ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_',' ', $t))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Date Format</label>
                <select name="date_format">
                    <?php foreach (['M j, Y' => 'Aug 22, 2026', 'Y-m-d' => '2026-08-22', 'd/m/Y' => '22/08/2026'] as $fmt => $sample): ?>
                    <option value="<?= e($fmt) ?>" <?= get_setting('date_format') === $fmt ? 'selected' : '' ?>><?= e($fmt) ?> (<?= e($sample) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Time Format</label>
                <select name="time_format">
                    <?php foreach (['g:i A' => '12-hour', 'H:i' => '24-hour'] as $fmt => $sample): ?>
                    <option value="<?= e($fmt) ?>" <?= get_setting('time_format') === $fmt ? 'selected' : '' ?>><?= e($sample) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Time Zone</label>
                <select name="timezone">
                    <?php foreach (['Asia/Manila','Asia/Singapore','Asia/Tokyo','UTC','America/New_York','Europe/London'] as $tz): ?>
                    <option value="<?= $tz ?>" <?= get_setting('timezone') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Currency</label>
                <select name="currency">
                    <?php foreach (['PHP','USD','EUR','JPY','SGD'] as $cur): ?>
                    <option value="<?= $cur ?>" <?= get_setting('currency') === $cur ? 'selected' : '' ?>><?= e($cur) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full-width"><label>Working Days (comma-separated)</label><input type="text" name="working_days" value="<?= e(get_setting('working_days')) ?>"></div>
            <div class="form-group"><label>Business Hours Start</label><input type="time" name="business_hours_start" value="<?= e(get_setting('business_hours_start')) ?>"></div>
            <div class="form-group"><label>Business Hours End</label><input type="time" name="business_hours_end" value="<?= e(get_setting('business_hours_end')) ?>"></div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save HR Preferences</button>
        </div>
    </form>
</section>

<!-- 6. RECRUITMENT -->
<section id="recruitment" class="panel fade-in-up" style="animation-delay:.3s">
    <h2>Recruitment Settings</h2>
    <p class="panel-desc">Defaults applied to new applications and postings.</p>
    <form method="post" class="form-panel compact-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_recruitment">
        <div class="form-grid">
            <div class="form-group"><label>Default Application Status</label>
                <select name="rec_default_status">
                    <?php foreach (['new','screening','shortlisted','interview'] as $st): ?>
                    <option value="<?= $st ?>" <?= get_setting('rec_default_status') === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Default Recruitment Notifications</label>
                <select name="rec_notify_default">
                    <option value="1" <?= get_setting('rec_notify_default') === '1' ? 'selected' : '' ?>>Enabled</option>
                    <option value="0" <?= get_setting('rec_notify_default') === '0' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </div>
            <div class="form-group full-width"><label>Recruitment Stages (comma-separated)</label><input type="text" name="rec_stages" value="<?= e(get_setting('rec_stages')) ?>"></div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Recruitment Settings</button>
        </div>
    </form>
</section>

<!-- 7. ATTENDANCE & LEAVE -->
<section id="attendance-leave" class="panel fade-in-up" style="animation-delay:.35s">
    <h2>Attendance &amp; Leave Settings</h2>
    <p class="panel-desc">Policy configuration only — employees submit their own requests through the Employee portal.</p>
    <form method="post" class="form-panel compact-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_attendance">
        <div class="form-grid">
            <div class="form-group"><label>Working Hours per Day</label><input type="number" min="1" max="16" step="0.5" name="att_work_hours" value="<?= e(get_setting('att_work_hours', '8')) ?>"></div>
            <div class="form-group"><label>Late Threshold (minutes)</label><input type="number" min="0" max="120" name="att_late_threshold" value="<?= e(get_setting('att_late_threshold', '15')) ?>"></div>
            <div class="form-group"><label>Overtime Tracking</label>
                <select name="att_overtime_enabled">
                    <option value="0" <?= get_setting('att_overtime_enabled') === '0' ? 'selected' : '' ?>>Disabled</option>
                    <option value="1" <?= get_setting('att_overtime_enabled') === '1' ? 'selected' : '' ?>>Enabled</option>
                </select>
            </div>
            <div class="form-group"><label>Leave Approval Workflow</label>
                <select name="leave_approval_flow">
                    <option value="hr" <?= get_setting('leave_approval_flow') === 'hr' ? 'selected' : '' ?>>HR approval</option>
                    <option value="manager" <?= get_setting('leave_approval_flow') === 'manager' ? 'selected' : '' ?>>Manager approval</option>
                    <option value="either" <?= get_setting('leave_approval_flow') === 'either' ? 'selected' : '' ?>>Manager or HR</option>
                </select>
            </div>
            <div class="form-group"><label>Leave Duration Unit</label>
                <select name="leave_duration_unit">
                    <option value="days" <?= get_setting('leave_duration_unit') === 'days' ? 'selected' : '' ?>>Days</option>
                    <option value="hours" <?= get_setting('leave_duration_unit') === 'hours' ? 'selected' : '' ?>>Hours</option>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Attendance &amp; Leave Settings</button>
        </div>
    </form>
</section>

<!-- 8. SYSTEM (ADMIN ONLY) -->
<section id="system" class="panel fade-in-up" style="animation-delay:.4s">
    <h2>System Configuration</h2>
    <?php if ($isAdmin): ?>
    <p class="panel-desc">Sensitive system-wide settings. Changes take effect across the platform.</p>
    <form method="post" class="form-panel compact-form" onsubmit="return confirm('Apply system configuration changes?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_system">
        <div class="form-grid">
            <div class="form-group"><label>System Name</label><input type="text" name="sys_name" value="<?= e(get_setting('sys_name', APP_NAME)) ?>"></div>
            <div class="form-group"><label>Default Language</label>
                <select name="sys_language">
                    <option value="en" <?= get_setting('sys_language') === 'en' ? 'selected' : '' ?>>English</option>
                    <option value="fil" <?= get_setting('sys_language') === 'fil' ? 'selected' : '' ?>>Filipino</option>
                </select>
            </div>
            <div class="form-group"><label>Maintenance Mode</label>
                <select name="sys_maintenance">
                    <option value="0" <?= get_setting('sys_maintenance') === '0' ? 'selected' : '' ?>>Off</option>
                    <option value="1" <?= get_setting('sys_maintenance') === '1' ? 'selected' : '' ?>>On (banner shown)</option>
                </select>
            </div>
        </div>
        <div class="detail-grid" style="margin-top:.75rem;">
            <div class="detail-item"><label>System Status</label><span><span class="live-dot"></span> Operational</span></div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save System Configuration</button>
        </div>
    </form>
    <?php else: ?>
    <p class="panel-desc">System-level configuration is restricted to Admin accounts. Ask an administrator to adjust these values.</p>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
