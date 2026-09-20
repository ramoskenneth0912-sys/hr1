<?php
/**
 * HR/ADMIN SETTINGS — administrative configuration area.
 * Sections: Account, Security, Notifications, Users & Roles,
 * HR Preferences, Recruitment, System (admin-only).
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();
require_once __DIR__ . '/../../includes/security_log.php';

if (isEmployee()) {
    flash('info', 'Employee settings are managed from your profile.');
    redirect(BASE_URL . '/modules/employee/profile.php');
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

    if ($action === 'save_system') {
        if (!$isAdmin) {
            flash('danger', 'Only Admin users may change system configuration.');
        } else {
            save_settings(['sys_name', 'sys_language']);
            flash('success', 'System configuration saved.');
        }
        redirect(BASE_URL . '/modules/settings/index.php#system');
    }

    if ($action === 'save_maintenance') {
        if (!$isAdmin) {
            flash('danger', 'Only Admin users may change maintenance mode.');
            redirect(BASE_URL . '/modules/settings/index.php#system');
        }

        $mode = strtolower(trim((string) ($_POST['maintenance_mode'] ?? 'off')));
        if (!in_array($mode, maintenance_valid_modes(), true)) {
            $mode = 'off';
        }
        $oldMode = maintenance_store_mode();

        $message = trim((string) ($_POST['maintenance_message'] ?? ''));
        $message = (string) preg_replace('/\R+/u', ' ', $message);
        $message = mb_substr($message, 0, 500);

        $endRaw   = trim((string) ($_POST['maintenance_end_at'] ?? ''));
        $endAt    = $endRaw !== '' ? $endRaw : null;

        $dtValid = static function (?string $v): bool {
            if ($v === null) {
                return true;
            }
            return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $v) === 1;
        };
        if (!$dtValid($endAt)) {
            flash('danger', 'Invalid maintenance schedule. Use the date/time pickers.');
            redirect(BASE_URL . '/modules/settings/index.php#system');
        }

        $modules = [];
        if ($mode === 'limited' && isset($_POST['maintenance_modules']) && is_array($_POST['maintenance_modules'])) {
            foreach ($_POST['maintenance_modules'] as $slug) {
                $slug = (string) $slug;
                if (isset(MAINTENANCE_MODULE_DEFS[$slug]) && !in_array($slug, $modules, true)) {
                    $modules[] = $slug;
                }
            }
        }
        $modulesJson = $modules !== [] ? json_encode($modules) : null;

        $stmt = db()->prepare(
            'UPDATE maintenance_settings
             SET mode = :mode, message = :message, start_at = NULL, end_at = :end_at,
                 selected_modules = :modules, updated_by = :uid
             WHERE id = 1'
        );
        $stmt->execute([
            ':mode'     => $mode,
            ':message'  => $message !== '' ? $message : null,
            ':end_at'   => $endAt,
            ':modules'  => $modulesJson,
            ':uid'      => $uid,
        ]);

        $summary = 'mode=' . $oldMode . '->' . $mode
            . ($mode === 'limited' ? ' modules=[' . implode(',', $modules) . ']' : '')
            . ($endAt !== null ? ' end=' . $endAt : '');
        securityLog('maintenance_mode_change', $summary, $uid);

        flash('success', 'Maintenance mode saved.');
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


$pageTitle = 'Settings';
$currentModule = 'settings';
$bodyClass = 'page-dashboard';

require_once __DIR__ . '/../../includes/header.php';

$navItems = [
    'account' => 'Account', 'security' => 'Security', 'notifications' => 'Notifications',
    'hr-preferences' => 'HR Preferences',
    'recruitment' => 'Recruitment',
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
        <fieldset style="border:none; margin:0 0 1.25rem; padding:0;">
            <legend style="font-weight:700; font-size:.875rem; color:var(--text-dark); padding:0; margin-bottom:.6rem;"><?= e($groupLabel) ?></legend>
            <div class="form-grid">
                <?php foreach ($group as $key => $label): ?>
                <label class="notif-option">
                    <input type="checkbox" name="<?= e($key) ?>" <?= (!isset($prefs[$key]) || $prefs[$key]) ? 'checked' : '' ?>>
                    <span><?= e($label) ?></span>
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

<!-- 4. HR PREFERENCES -->
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

<!-- 5. RECRUITMENT -->
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

<!-- 7. SYSTEM (ADMIN ONLY) -->
<section id="system" class="panel fade-in-up" style="animation-delay:.4s">
    <h2>System Configuration</h2>
    <?php if ($isAdmin): ?>
    <p class="panel-desc">Sensitive system-wide settings. Changes take effect across the platform.</p>

    <?php
    $mmxCfg      = maintenance_config();
    $mmxMode     = maintenance_mode();
    $mmxStored   = maintenance_store_mode();
    $mmxSelected = maintenance_selected_modules();
    $mmxMsg      = trim((string) ($mmxCfg['message'] ?? ''));
    $mmxFmt      = static function ($v): string { return $v ? substr(str_replace(' ', 'T', (string) $v), 0, 16) : ''; };
    $mmxEnd      = $mmxFmt($mmxCfg['end_at'] ?? '');
    $modeLabels  = [
        'off'     => 'Off',
        'limited' => 'Limited (block selected modules)',
        'full'    => 'Full (everything down)',
    ];
    $modeHints   = [
        'off'     => 'System fully available, no banner.',
        'limited' => 'Only the modules you select below are blocked (503 page). Everything else works and shows a banner.',
        'full'    => 'Whole system down for everyone except HR admins, who keep full access so they can manage this screen.',
    ];
    $statusText  = $mmxMode === 'off' ? 'Operational' : 'Maintenance active — ' . ucfirst($mmxMode) . ' mode';
    ?>

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
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save System Configuration</button>
        </div>
    </form>

    <h3 style="margin-top:1.5rem;">Maintenance Mode</h3>
    <p class="panel-desc">
    <form method="post" class="form-panel compact-form" id="maintenanceForm" onsubmit="return confirm('Apply maintenance mode changes?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_maintenance">

        <div class="detail-grid" style="margin-bottom:.75rem;">
            <div class="detail-item"><label>System Status</label>
                <span style="<?= $mmxMode === 'off' ? '' : 'color:var(--warning);font-weight:700;' ?>">
                    <?php if ($mmxMode === 'off'): ?><span class="live-dot"></span><?php endif; ?>
                    <?= e($statusText) ?>
                </span>
            </div>
        </div>

        <div class="form-grid">
            <?php foreach ($modeLabels as $val => $label): ?>
            <label class="mmx-radio">
                <input type="radio" name="maintenance_mode" value="<?= e($val) ?>" <?= $mmxStored === $val ? 'checked' : '' ?> data-mmx-mode>
                <span class="mmx-radio-box">
                    <strong><?= e($label) ?></strong>
                    <small><?= e($modeHints[$val]) ?></small>
                </span>
            </label>
            <?php endforeach; ?>
        </div>

        <div class="mmx-hidden" style="margin-top:.9rem;" id="mmxModuleBlock">
            <div class="form-group">
                <label>Modules to block (Limited mode)</label>
                <div class="mmx-modules">
                    <?php foreach (MAINTENANCE_MODULE_DEFS as $slug => $def): ?>
                    <label class="mmx-chip">
                        <input type="checkbox" name="maintenance_modules[]" value="<?= e($slug) ?>" <?= in_array($slug, $mmxSelected, true) ? 'checked' : '' ?>>
                        <span><?= e($def['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="mmx-hidden form-group full-width" style="margin-top:.9rem;" id="mmxScheduleBlock">
            <label>Schedule End (optional) </label>
            <input type="datetime-local" name="maintenance_end_at" value="<?= e($mmxEnd) ?>">
        </div>

        <div class="mmx-hidden form-group full-width" style="margin-top:.9rem;" id="mmxMessageBlock">
            <label>Message (optional)</label>
            <input type="text" name="maintenance_message" maxlength="500" value="<?= e($mmxMsg) ?>" placeholder="Leave blank to use the default message for the selected mode.">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Maintenance Mode</button>
        </div>
    </form>

    <style>
        .mmx-hidden{ display:none !important; }
        .mmx-radio{ cursor:pointer; display:block; }
        .mmx-radio input{ position:absolute; opacity:0; pointer-events:none; }
        .mmx-radio-box{ display:flex; flex-direction:column; gap:.15rem; border:1px solid var(--border,#E9EDF7); border-radius:var(--radius,14px); padding:.7rem .85rem; background:var(--surface,#fff); transition:border-color .15s ease, box-shadow .15s ease; }
        .mmx-radio input:checked + .mmx-radio-box{ border-color:var(--purple,#7B2CBF); box-shadow:0 0 0 3px rgba(123,44,191,.14); }
        .mmx-radio-box small{ color:var(--muted,#77809b); font-size:.72rem; line-height:1.4; }
        .mmx-modules{ display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.35rem; }
        .mmx-chip{ display:inline-flex; align-items:center; gap:.4rem; cursor:pointer; }
        .mmx-chip span{ border:1px solid var(--border,#E9EDF7); border-radius:999px; padding:.35rem .8rem; font-size:.78rem; background:var(--surface,#fff); transition:border-color .15s ease, box-shadow .15s ease; }
        .mmx-chip input{ position:absolute; opacity:0; pointer-events:none; }
        .mmx-chip input:checked + span{ border-color:var(--purple,#7B2CBF); box-shadow:0 0 0 2px rgba(123,44,191,.18); color:var(--text,#344054); font-weight:600; }
    </style>
    <script>
    (function () {
        var modeInputs = document.querySelectorAll('#maintenanceForm input[data-mmx-mode]');
        var moduleBlock = document.getElementById('mmxModuleBlock');
        var scheduleBlock = document.getElementById('mmxScheduleBlock');
        var messageBlock = document.getElementById('mmxMessageBlock');
        function setHidden(el, hide) {
            if (!el) return;
            if (hide) { el.classList.add('mmx-hidden'); } else { el.classList.remove('mmx-hidden'); }
        }
        function sync() {
            var v = null;
            modeInputs.forEach(function (i) { if (i.checked) v = i.value; });
            setHidden(moduleBlock, v !== 'limited');
            setHidden(scheduleBlock, v === 'off');
            setHidden(messageBlock, v === 'off');
        }
        modeInputs.forEach(function (i) { i.addEventListener('change', sync); });
        sync();
    })();
    </script>
    <?php else: ?>
    <p class="panel-desc">System-level configuration is restricted to Admin accounts. Ask an administrator to adjust these values.</p>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
