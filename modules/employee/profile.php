<?php
/**
 * MY PROFILE — personal & employment information ONLY.
 * Leave requests live in leave.php; documents in documents.php.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

const ALLOWED_PHOTO_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
const MAX_PHOTO_BYTES = 2 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    $user = getCurrentUser();
    $employeeId = $user['employee_id'] ?? null;

    if (!$employeeId || $action !== 'update_profile') {
        flash('danger', 'No employee profile linked to your account.');
        redirect(BASE_URL . '/modules/employee/profile.php');
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
        redirect(BASE_URL . '/modules/employee/profile.php');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'A valid email address is required.');
        redirect(BASE_URL . '/modules/employee/profile.php');
    }

    // Email must stay unique across employees and user accounts.
    $dupEmp = db()->prepare('SELECT id FROM employees WHERE email = ? AND id <> ?');
    $dupEmp->execute([$email, $employeeId]);
    $dupUser = db()->prepare('SELECT id FROM users WHERE email = ? AND employee_id IS NULL');
    $dupUser->execute([$email]);
    if ($dupEmp->fetch() || $dupUser->fetch()) {
        flash('danger', 'That email address is already in use.');
        redirect(BASE_URL . '/modules/employee/profile.php');
    }
    if ($phone !== '' && !preg_match('/^[0-9+\-\s().]{7,20}$/', $phone)) {
        flash('danger', 'Phone number may contain digits, spaces, + - ( ) . and must be 7-20 characters.');
        redirect(BASE_URL . '/modules/employee/profile.php');
    }
    if (($dobErr = validateDateOfBirth($_POST['birth_date'] ?? null)) !== null) {
        flash('danger', $dobErr);
        redirect(BASE_URL . '/modules/employee/profile.php');
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
        if (!isset(ALLOWED_PHOTO_MIME[$mime]) || $file['size'] > MAX_PHOTO_BYTES) {
            flash('warning', 'Profile picture not saved — use a JPG or PNG image up to 2 MB.');
        } else {
            $photoDirAbs = dirname(__DIR__, 2) . '/uploads/profile/emp' . (int) $employeeId;
            $photoRel = 'uploads/profile/emp' . (int) $employeeId;
            if (!is_dir($photoDirAbs)) {
                @mkdir($photoDirAbs, 0775, true);
            }
            $storedName = bin2hex(random_bytes(8)) . '.' . ALLOWED_PHOTO_MIME[$mime];
            if (move_uploaded_file($file['tmp_name'], $photoDirAbs . '/' . $storedName)) {
                db()->prepare('UPDATE ess_profiles SET photo_path = ? WHERE employee_id = ?')
                    ->execute([$photoRel . '/' . $storedName, $employeeId]);
            } else {
                flash('warning', 'Profile picture could not be stored.');
            }
        }
    }

    flash('success', 'Profile updated.');
    redirect(BASE_URL . '/modules/employee/profile.php');
}

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
</div>

<div class="two-col">
    <section class="panel fade-in-up" style="animation-delay:.1s">
        <h2>Personal Information</h2>
        <div class="profile-photo-row">
            <?php if (!empty($profile['photo_path'])): ?>
            <img class="profile-photo" src="<?= BASE_URL ?>/<?= e($profile['photo_path']) ?>" alt="Profile photo">
            <?php else: ?>
            <span class="profile-photo profile-photo-empty"><?= e(mb_strtoupper(mb_substr($employee['first_name'] ?? 'E', 0, 1))) ?></span>
            <?php endif; ?>
            <div class="detail-grid" style="flex:1;">
                <div class="detail-item"><label>Employee No.</label><span><?= e($employee['employee_no'] ?? '—') ?></span></div>
                <div class="detail-item"><label>Job Title</label><span><?= e($employee['job_title'] ?? '—') ?></span></div>
                <div class="detail-item"><label>Department</label><span><?= e($employee['department_name'] ?? '—') ?></span></div>
                <div class="detail-item"><label>Employment Type</label><span><?= e(ucfirst(str_replace('_', ' ', $employee['employment_type'] ?? '—'))) ?></span></div>
                <div class="detail-item"><label>Hire Date</label><span><?= formatDate($employee['hire_date'] ?? null) ?></span></div>
                <div class="detail-item"><label>Status</label><span><?= statusBadge($employee['status'] ?? 'active') ?></span></div>
                <div class="detail-item full-width"><label>Full Name</label><span><?= e(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')) ?> · <?= e($employee['email'] ?? '—') ?></span></div>
            </div>
        </div>
        <p class="panel-desc">Employee No., role, department and employment status can only be changed by HR.</p>
    </section>

    <section class="panel fade-in-up" style="animation-delay:.2s">
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
</div>

<section class="panel fade-in-up" style="animation-delay:.25s">
    <h2>My Skills &amp; Background</h2>
    <div class="detail-grid">
        <div class="detail-item"><label>Education</label><span><?= e($profile['education'] ?? '') ?: '—' ?></span></div>
        <div class="detail-item full-width"><label>Skills</label><span><?= nl2br(e($profile['skills'] ?? '')) ?: '—' ?></span></div>
        <div class="detail-item full-width"><label>Work Experience</label><span><?= nl2br(e($profile['work_experience'] ?? '')) ?: '—' ?></span></div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
