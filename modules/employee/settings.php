<?php
/**
 * SETTINGS — account & security settings and profile editing.
 * Includes: Change Password, Account, and Edit My Profile.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

const ALLOWED_PHOTO_MIME_EMP = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
const MAX_PHOTO_BYTES_EMP = 2 * 1024 * 1024;

$user = getCurrentUser();
$employeeId = $user['employee_id'] ?? null;

/* ---- POST handlers (password change) ------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrf_require();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
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
            ->execute([password_hash($new, PASSWORD_DEFAULT), (int) $_SESSION['user_id']]);
        session_regenerate_id(true);
        csrf_rotate();
        flash('success', 'Password changed successfully.');
    }
    redirect(BASE_URL . '/modules/employee/settings.php');
}

/* ---- POST handler (update profile) -------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    csrf_require();
    $employeeId = $employeeId ?? ($user['employee_id'] ?? null);

    if (!$employeeId) {
        flash('danger', 'No employee profile linked to your account.');
        redirect(BASE_URL . '/modules/employee/settings.php');
    }

    // --- Self-service identity fields (NOT employee_no / role / dept / status)
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $education = mb_substr(trim($_POST['education'] ?? ''), 0, 150);
    $skills = mb_substr(trim($_POST['skills'] ?? ''), 0, 2000);
    $workExperience = mb_substr(trim($_POST['work_experience'] ?? ''), 0, 3000);

    if ($firstName === '' || $lastName === '') {
        flash('danger', 'First name and last name are required.');
        redirect(BASE_URL . '/modules/employee/settings.php');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'A valid email address is required.');
        redirect(BASE_URL . '/modules/employee/settings.php');
    }

    // Email must stay unique across employees and user accounts.
    $dupEmp = db()->prepare('SELECT id FROM employees WHERE email = ? AND id <> ?');
    $dupEmp->execute([$email, $employeeId]);
    $dupUser = db()->prepare('SELECT id FROM users WHERE email = ? AND employee_id IS NULL');
    $dupUser->execute([$email]);
    if ($dupEmp->fetch() || $dupUser->fetch()) {
        flash('danger', 'That email address is already in use.');
        redirect(BASE_URL . '/modules/employee/settings.php');
    }
    if ($phone !== '' && !preg_match('/^[0-9+\-\s().]{7,20}$/', $phone)) {
        flash('danger', 'Phone number may contain digits, spaces, + - ( ) . and must be 7-20 characters.');
        redirect(BASE_URL . '/modules/employee/settings.php');
    }
    if (($dobErr = validateDateOfBirth($_POST['birth_date'] ?? null)) !== null) {
        flash('danger', $dobErr);
        redirect(BASE_URL . '/modules/employee/settings.php');
    }

    db()->prepare(
        'UPDATE employees SET first_name=?, last_name=?, email=?, phone=? WHERE id=?'
    )->execute([$firstName, $lastName, $email, $phone !== '' ? $phone : null, $employeeId]);

    $exists = db()->prepare('SELECT id FROM ess_profiles WHERE employee_id = ?');
    $exists->execute([$employeeId]);

    if ($exists->fetch()) {
        db()->prepare(
            'UPDATE ess_profiles SET emergency_contact_name=?, emergency_contact_phone=?,
             address=?, birth_date=?, marital_status=?, education=?, skills=?, work_experience=?
             WHERE employee_id=?'
        )->execute([
            trim($_POST['emergency_contact_name'] ?? ''),
            trim($_POST['emergency_contact_phone'] ?? ''),
            $address,
            $_POST['birth_date'] ?: null,
            $_POST['marital_status'],
            $education,
            $skills,
            $workExperience,
            $employeeId,
        ]);
    } else {
        db()->prepare(
            'INSERT INTO ess_profiles (employee_id, emergency_contact_name, emergency_contact_phone,
             address, birth_date, marital_status, education, skills, work_experience)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $employeeId,
            trim($_POST['emergency_contact_name'] ?? ''),
            trim($_POST['emergency_contact_phone'] ?? ''),
            $address,
            $_POST['birth_date'] ?: null,
            $_POST['marital_status'],
            $education,
            $skills,
            $workExperience,
            $employeeId,
        ]);
    }

    // --- Optional profile picture
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_photo'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!isset(ALLOWED_PHOTO_MIME_EMP[$mime]) || $file['size'] > MAX_PHOTO_BYTES_EMP) {
            flash('warning', 'Profile picture not saved — use a JPG or PNG image up to 2 MB.');
        } else {
            $photoDirAbs = dirname(__DIR__, 2) . '/uploads/profile/emp' . (int) $employeeId;
            $photoRel = 'uploads/profile/emp' . (int) $employeeId;
            if (!is_dir($photoDirAbs)) {
                @mkdir($photoDirAbs, 0775, true);
            }
            $storedName = bin2hex(random_bytes(8)) . '.' . ALLOWED_PHOTO_MIME_EMP[$mime];
            if (move_uploaded_file($file['tmp_name'], $photoDirAbs . '/' . $storedName)) {
                db()->prepare('UPDATE ess_profiles SET photo_path = ? WHERE employee_id = ?')
                    ->execute([$photoRel . '/' . $storedName, $employeeId]);
            } else {
                flash('warning', 'Profile picture could not be stored.');
            }
        }
    }

    flash('success', 'Profile updated.');
    redirect(BASE_URL . '/modules/employee/settings.php');
}

/* ---- display data --------------------------------------------------------- */
$employee = null;
$profile = [];
if ($employeeId) {
    $emp = db()->prepare(
        'SELECT e.*, d.name AS department_name
         FROM employees e
         LEFT JOIN departments d ON e.department_id = d.id
         WHERE e.id = ?'
    );
    $emp->execute([$employeeId]);
    $employee = $emp->fetch();

    $pStmt = db()->prepare('SELECT * FROM ess_profiles WHERE employee_id = ?');
    $pStmt->execute([$employeeId]);
    $profile = $pStmt->fetch() ?: [];
}

$pageTitle = 'Settings';
$currentModule = 'settings';
$bodyClass = 'page-dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-subtitle">Account security, preferences and profile editing</p>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Change Password</h2>
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
                <small class="panel-desc">At least 8 characters with letters and numbers.</small>
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
</section>

<?php if ($employee): ?>
<section class="panel fade-in-up" style="animation-delay:.15s">
    <h2>Edit My Profile</h2>
    <form method="post" enctype="multipart/form-data" class="form-panel compact-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="form-grid">
            <div class="form-group">
                <label for="first_name">First Name *</label>
                <input type="text" id="first_name" name="first_name" value="<?= e($employee['first_name'] ?? '') ?>" required maxlength="80">
            </div>
            <div class="form-group">
                <label for="last_name">Last Name *</label>
                <input type="text" id="last_name" name="last_name" value="<?= e($employee['last_name'] ?? '') ?>" required maxlength="80">
            </div>
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" value="<?= e($employee['email'] ?? '') ?>" required maxlength="120">
            </div>
            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" value="<?= e($employee['phone'] ?? '') ?>" maxlength="30">
            </div>
            <div class="form-group full-width">
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="2"><?= e($profile['address'] ?? '') ?></textarea>
            </div>
            <div class="form-group full-width">
                <label for="education">Education</label>
                <input type="text" id="education" name="education" value="<?= e($profile['education'] ?? '') ?>" maxlength="150" placeholder="e.g. BS Information Technology, 2020">
            </div>
            <div class="form-group full-width">
                <label for="skills">Skills (comma separated)</label>
                <textarea id="skills" name="skills" rows="2"><?= e($profile['skills'] ?? '') ?></textarea>
            </div>
            <div class="form-group full-width">
                <label for="work_experience">Work Experience</label>
                <textarea id="work_experience" name="work_experience" rows="3"><?= e($profile['work_experience'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label for="emergency_contact_name">Emergency Contact Name</label>
                <input type="text" id="emergency_contact_name" name="emergency_contact_name" value="<?= e($profile['emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="emergency_contact_phone">Emergency Contact Phone</label>
                <input type="text" id="emergency_contact_phone" name="emergency_contact_phone" value="<?= e($profile['emergency_contact_phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="birth_date">Birth Date</label>
                <input type="date" id="birth_date" name="birth_date" value="<?= e($profile['birth_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label for="marital_status">Marital Status</label>
                <select id="marital_status" name="marital_status">
                    <?php foreach (['single','married','widowed','separated'] as $m): ?>
                    <option value="<?= $m ?>" <?= ($profile['marital_status'] ?? 'single') === $m ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full-width">
                <label for="profile_photo">Profile Picture (JPG/PNG, max 2 MB)</label>
                <input type="file" id="profile_photo" name="profile_photo" accept=".jpg,.jpeg,.png">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Profile</button>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="panel fade-in-up" style="animation-delay:.2s">
    <h2>Account</h2>
    <div class="detail-grid">
        <div class="detail-item"><label>Signed in as</label><span><?= e($user['username']) ?> · <?= e(ucfirst($user['role'])) ?></span></div>
    </div>
    <p class="panel-desc">To sign out on this device, use the Logout button in the header. Your displayed profile information is managed under My Profile.</p>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
