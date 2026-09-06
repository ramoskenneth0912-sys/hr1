<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/ai_screening.php';

const RESUME_MAX_BYTES = 5 * 1024 * 1024; // 5 MB, matches the hint shown in the form
const APPLY_MAX_SUBMISSIONS = 20;         // successful submissions per IP per window
const APPLY_WINDOW_SECONDS = 900;         // 15 minutes

if (isLoggedIn() && isEmployee() && !maintenance_is_active()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$loggedInUserId = isLoggedIn() ? $_SESSION['user_id'] : null;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    redirect(BASE_URL . '/public/jobs.php');
}

$stmt = db()->prepare('SELECT j.*, d.name AS department_name FROM job_postings j LEFT JOIN departments d ON j.department_id = d.id WHERE j.id = ?');
$stmt->execute([$id]);
$job = $stmt->fetch();

if (!$job) {
    redirect(BASE_URL . '/public/jobs.php');
}

$errors = [];
$oldName = '';
$oldEmail = '';
$oldPhone = '';
$oldAddress = '';
$oldCoverLetter = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    // Throttle bulk application spam: allow up to 20 successful submissions
    // per IP per 15 minutes (same bound as the API "apply" endpoint).
    $applyKey = 'webapply_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (!webRateLimit($applyKey, APPLY_MAX_SUBMISSIONS, APPLY_WINDOW_SECONDS)) {
        $errors[] = 'Too many applications from this location. Please try again in 15 minutes.';
    }

    $oldName = trim($_POST['full_name'] ?? '');
    $oldEmail = trim($_POST['email'] ?? '');
    $oldPhone = trim($_POST['phone'] ?? '');
    $oldAddress = trim($_POST['address'] ?? '');
    $oldCoverLetter = trim($_POST['cover_letter'] ?? '');

    if ($oldName === '') {
        $errors[] = 'Full name is required.';
    }
    if ($oldEmail === '' || !filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if ($oldPhone === '') {
        $errors[] = 'Contact number is required.';
    }

    if (!isset($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please upload your CV/Resume.';
    } else {
        if ((int) ($_FILES['resume']['size'] ?? 0) <= 0) {
            $errors[] = 'The uploaded resume is empty.';
        } elseif ((int) $_FILES['resume']['size'] > RESUME_MAX_BYTES) {
            $errors[] = 'Resume is too large. Maximum size is 5 MB.';
        } else {
            $allowed = ['pdf', 'doc', 'docx'];
            $ext = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                $errors[] = 'Resume must be a PDF, DOC, or DOCX file.';
            } else {
                $allowedMimes = [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($_FILES['resume']['tmp_name']);
                if (!in_array($mimeType, $allowedMimes, true)) {
                    $errors[] = 'Uploaded file type is not allowed.';
                }
            }
        }
    }

    if (empty($errors)) {
        $applicantNo = generateCode('APP', 'applicants', 'applicant_no');

        $uploadDir = __DIR__ . '/../uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Randomized stored name (allowlisted extension) — avoids predictable,
        // user-controlled filenames and any path/traversal ambiguity.
        if (!isset($ext) || !in_array($ext, ['pdf', 'doc', 'docx'], true)) {
            $errors[] = 'Uploaded file type is not allowed.';
        } else {
            $storedName = $applicantNo . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $storedPath = 'uploads/' . $storedName;

            if (move_uploaded_file($_FILES['resume']['tmp_name'], $uploadDir . '/' . $storedName)) {
                $insert = db()->prepare(
                    'INSERT INTO applicants (user_id, applicant_no, first_name, last_name, email, phone, address, position_applied, department_id, job_posting_id, resume_path, status, applied_date, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)'
                );

                $fullName = $oldName;
                $nameParts = explode(' ', $fullName, 2);
                $firstName = $nameParts[0];
                $lastName = $nameParts[1] ?? '';

                $insert->execute([
                    $loggedInUserId,
                    $applicantNo,
                    $firstName,
                    $lastName,
                    $oldEmail,
                    $oldPhone,
                    $oldAddress,
                    $job['title'],
                    $job['department_id'],
                    $job['id'],
                    $storedPath,
                    'new',
                    $oldCoverLetter,
                ]);

                webRateLimitRecord($applyKey);

                // Applicant + application successfully created with their CV.
                // Now tell every active HR/Admin account via the existing
                // notification bell. Runs after the insert succeeds so a failed
                // submission never produces a "New Applicant" notification.
                $newApplicantId = (int) db()->lastInsertId();
                notifyHRofNewApplicant($newApplicantId, $fullName, $job['title']);

                // AUTOMATIC AI resume matching: compare the uploaded CV against
                // this job right away and store the 0-100 match result for the
                // applicants list. autoScreenApplicant() never throws and never
                // blocks submission — on any failure it is recorded as
                // "Unavailable" and the applicant/application stay intact.
                try {
                    autoScreenApplicant($newApplicantId);
                } catch (Throwable $e) {
                    error_log('[apply.php] automatic AI screening skipped for applicant_id=' . $newApplicantId . ': ' . $e->getMessage());
                }

                redirect(BASE_URL . '/public/thank_you.php');
            } else {
                $errors[] = 'Failed to upload file. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply - <?= e($job['title']) ?> - <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <style>
        :root { --pub-brand: #43109F; --pub-brand-dark: #36088C; --pub-footer: #351286; }
        html { scroll-behavior: smooth; }
        body { background: #fff; }
        .public-header {
            background: #fff;
            border-bottom: 1px solid #E7E5EE;
            padding: 0 clamp(20px, 3.3vw, 51px);
            min-height: 96px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .public-header .brand {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }
        .public-header .brand img { height: 68px; width: auto; display: block; }
        .header-actions { display: flex; align-items: center; gap: 22px; }
        .back-inline {
            font-size: 14.5px;
            font-weight: 600;
            color: #3F3D56;
            text-decoration: none;
            transition: color .2s ease;
        }
        .back-inline:hover { color: var(--pub-brand); }
        .btn-login {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 44px;
            padding: 0 28px;
            background: var(--pub-brand);
            color: #fff;
            font-size: 14.5px;
            font-weight: 600;
            border-radius: 10px;
            text-decoration: none;
            transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
        }
        .btn-login:hover {
            background: var(--pub-brand-dark);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(67,16,159,.22);
        }
        .apply-container {
            max-width: 720px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            font-size: .875rem;
            font-weight: 600;
            color: var(--purple);
            text-decoration: none;
            margin-bottom: 1.5rem;
        }
        .back-link:hover { color: var(--purple-dark); }
        .apply-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: .25rem;
        }
        .apply-subtitle {
            color: var(--muted);
            font-size: .875rem;
            margin-bottom: 1.5rem;
        }
        .job-info-bar {
            background: var(--purple-bg);
            border: 1px solid rgba(123, 44, 191, 0.2);
            border-radius: var(--radius-sm);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: .875rem;
        }
        .job-info-bar strong {
            color: var(--text-dark);
        }
        .form-group input[type="file"] {
            padding: .5rem;
        }
        .form-hint {
            font-size: .75rem;
            color: var(--muted);
            margin-top: .2rem;
        }
        .apply-container { animation: applyIn .55s ease both; }
        @keyframes applyIn {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .apply-container { animation: none; }
            * { transition-duration: .01ms !important; }
        }
        .public-footer {
            background: var(--pub-footer);
            color: rgba(255,255,255,.75);
            text-align: center;
            padding: 22px 16px;
            font-size: 12.5px;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../includes/maintenance_banner.php'; ?>
    <header class="public-header">
        <a href="jobs.php" class="brand" aria-label="TRI-M Global Logistics &amp; Trading Inc. — Jobs">
            <img src="../assets/images/tri-m-logo.png" alt="TRI-M GLOBAL — Logistics &amp; Trading Inc.">
        </a>
        <div class="header-actions">
            <a href="job_detail.php?id=<?= (int)$job['id'] ?>" class="back-inline">&larr; Back to Job</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="<?= BASE_URL ?>/index.php" class="btn-login">Dashboard</a>
            <?php else: ?>
                <a href="../auth/login.php" class="btn-login">Log In</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="apply-container">
        <a href="jobs.php" class="back-link">&larr; Back to Jobs</a>

        <h1 class="apply-title">Job Application</h1>
        <p class="apply-subtitle">Fill out the form below to apply for this position.</p>

        <div class="job-info-bar">
            <span><strong>Position:</strong> <?= e($job['title']) ?></span>
            <span><strong>Department:</strong> <?= e($job['department_name'] ?? 'N/A') ?></span>
            <?php if (!empty($job['work_location'])): ?>
                <span><strong>Location:</strong> <?= e($job['work_location']) ?></span>
            <?php endif; ?>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php
        require_once __DIR__ . '/../includes/resume_parser.php';
        $ocrCheck = ocrAvailabilityCheck();
        if (!$ocrCheck['ok']): ?>
            <div class="alert alert-warning">
                <strong>OCR not available on this server.</strong> Uploaded resumes are checked
                for readable text; resumes saved as images (scanned/photo PDFs) may not be
                analyzable until Tesseract OCR is installed. <?= e($ocrCheck['reason']) ?>
            </div>
        <?php endif; ?>

        <div class="form-panel">
            <form method="post" action="apply.php?id=<?= (int)$job['id'] ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="full_name">Full Name <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="full_name" name="full_name" required value="<?= e($oldName) ?>" placeholder="e.g. Juan Dela Cruz">
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address <span style="color:var(--danger)">*</span></label>
                        <input type="email" id="email" name="email" required value="<?= e($oldEmail) ?>" placeholder="you@example.com">
                    </div>
                    <div class="form-group">
                        <label for="phone">Contact Number <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="phone" name="phone" required value="<?= e($oldPhone) ?>" placeholder="09XX XXX XXXX">
                    </div>
                    <div class="form-group full-width">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" rows="2" placeholder="Street, Barangay, City, Province"><?= e($oldAddress) ?></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label for="cover_letter">Cover Letter / Message</label>
                        <textarea id="cover_letter" name="cover_letter" rows="5" placeholder="Tell us why you're a great fit for this position."><?= e($oldCoverLetter) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="position">Preferred Position <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="position" name="position" required value="<?= e($job['title']) ?>" readonly style="background:var(--bg);">
                    </div>
                    <div class="form-group full-width">
                        <label for="resume">CV / Resume <span style="color:var(--danger)">*</span></label>
                        <input type="file" id="resume" name="resume" required accept=".pdf,.doc,.docx">
                        <div class="form-hint">Accepted formats: PDF, DOC, DOCX (Max 5MB recommended)</div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Submit Application</button>
                    <a href="jobs.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <footer class="public-footer">
        &copy; <?= date('Y') ?> TRI-M Global Logistics &amp; Trading Inc. All rights reserved.
    </footer>
</body>
</html>
