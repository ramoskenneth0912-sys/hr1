<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
require_once __DIR__ . '/../../includes/onboarding.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('danger', 'Invalid applicant ID.');
    redirect(BASE_URL . '/modules/onboarding/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    $aStmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
    $aStmt->execute([$id]);
    $applicant = $aStmt->fetch();
    if (!$applicant) {
        flash('danger', 'Applicant not found.');
        redirect(BASE_URL . '/modules/onboarding/index.php');
    }

    // Server-side gate: onboarding is only valid for applicants who were
    // actually Hired. The list page filters to status='hired', but every POST
    // action here must re-verify it so a crafted request cannot drive a
    // non-hired applicant through orientation/document/employee-account steps.
    if (($applicant['status'] ?? '') !== 'hired') {
        flash('danger', 'Onboarding is only available for hired applicants.');
        redirect(BASE_URL . '/modules/onboarding/index.php');
    }

    if ($action === 'start_onboarding') {
        getOrCreateOnboardingProgress($id);
        securityLog('onboarding_started', "applicant_id={$id}", $_SESSION['user_id']);
        flash('success', 'Onboarding started.');
        redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
    }

    $progress = getOnboardingProgress($id);
    if (!$progress) {
        flash('danger', 'Onboarding has not been started for this applicant.');
        redirect(BASE_URL . '/modules/onboarding/index.php');
    }

    if ($action === 'schedule_orientation') {
        $orientDate = trim($_POST['orientation_date'] ?? '');
        $orientTime = trim($_POST['orientation_time'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $errors = [];
        if ($orientDate === '' || !isValidDateString($orientDate)) {
            $errors[] = 'A valid orientation date is required.';
        } elseif (strtotime($orientDate) < strtotime(date('Y-m-d'))) {
            $errors[] = 'Orientation date cannot be in the past.';
        }
        if ($orientTime === '') $errors[] = 'Orientation time is required.';
        if ($location === '') $errors[] = 'Location is required.';
        if ($errors) {
            flash('danger', implode(' ', $errors));
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        $exists = db()->prepare('SELECT id FROM orientation_schedules WHERE applicant_id = ?');
        $exists->execute([$id]);
        if ($exists->fetch()) {
            db()->prepare('UPDATE orientation_schedules SET orientation_date=?, orientation_time=?, location=?, venue=?, notes=? WHERE applicant_id=?')
                ->execute([$orientDate, $orientTime, $location, $venue ?: null, $notes ?: null, $id]);
        } else {
            db()->prepare('INSERT INTO orientation_schedules (applicant_id, orientation_date, orientation_time, location, venue, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $orientDate, $orientTime, $location, $venue ?: null, $notes ?: null, $_SESSION['user_id']]);
        }
        securityLog('orientation_scheduled', "applicant_id={$id} date={$orientDate}", $_SESSION['user_id']);
        flash('success', 'Orientation scheduled successfully.');
        redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
    }

    if ($action === 'complete_orientation') {
        if (!orientationCompleted($id)) {
            db()->prepare('UPDATE orientation_schedules SET is_completed=1, completed_at=NOW() WHERE applicant_id=? AND is_completed=0')->execute([$id]);
        }
        if ($progress['current_stage'] === 'orientation_scheduled') {
            advanceOnboardingStage($id);
        }
        securityLog('orientation_completed', "applicant_id={$id}", $_SESSION['user_id']);
        flash('success', 'Orientation marked as completed.');
        redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
    }

    if ($action === 'upload_document') {
        $docType = trim($_POST['document_type'] ?? '');
        $docName = trim($_POST['document_name'] ?? '');
        $allowedTypes = ['government_id', 'contract', 'diploma', 'medical_certificate', 'nbi_clearance', 'other'];
        $errors = [];
        if (!in_array($docType, $allowedTypes, true)) $errors[] = 'Invalid document type.';
        if ($docName === '') $errors[] = 'Document name is required.';
        if (empty($_FILES['document_file']['tmp_name']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) $errors[] = 'Please select a file to upload.';
        if ($errors) {
            flash('danger', implode(' ', $errors));
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        $file = $_FILES['document_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowedExts, true)) {
            flash('danger', 'File type not allowed.');
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            flash('danger', 'File is too large. Maximum 5 MB.');
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowedMimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
        if (!in_array($mime, $allowedMimes, true)) {
            flash('danger', 'Invalid file content type.');
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        $uploadDir = __DIR__ . '/../../uploads/onboarding/' . $id . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $safeFilename = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeFilename)) {
            flash('danger', 'Failed to save uploaded file.');
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        $relativePath = 'uploads/onboarding/' . $id . '/' . $safeFilename;
        db()->prepare('INSERT INTO onboarding_documents (applicant_id, document_type, document_name, file_path, verification_status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $docType, $docName, $relativePath, 'pending']);
        if (onboardingStageReached($progress['current_stage'], 'orientation_completed') && !onboardingStageReached($progress['current_stage'], 'documents_submitted')) {
            advanceOnboardingStage($id);
        }
        securityLog('onboarding_doc_uploaded', "applicant_id={$id} type={$docType}", $_SESSION['user_id']);
        flash('success', 'Document uploaded successfully.');
        redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
    }

    if ($action === 'verify_document') {
        $docId = (int) ($_POST['document_id'] ?? 0);
        $vStatus = $_POST['verification_status'] ?? '';
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($docId <= 0 || !in_array($vStatus, ['verified', 'rejected'], true)) {
            flash('danger', 'Invalid verification request.');
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        if ($vStatus === 'rejected' && $reason === '') {
            flash('danger', 'A rejection reason is required.');
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        db()->prepare('UPDATE onboarding_documents SET verification_status=?, verified_by=?, verified_at=NOW(), rejection_reason=? WHERE id=? AND applicant_id=?')
            ->execute([$vStatus, $_SESSION['user_id'], $vStatus === 'rejected' ? $reason : null, $docId, $id]);
        if (allRequiredDocumentsVerified($id) && $progress['current_stage'] === 'documents_submitted') {
            advanceOnboardingStage($id);
        }
        securityLog('onboarding_doc_verified', "applicant_id={$id} doc_id={$docId} status={$vStatus}", $_SESSION['user_id']);
        flash('success', 'Document ' . $vStatus . '.');
        redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
    }

    if ($action === 'create_employee_account') {
        if (!allRequiredDocumentsVerified($id)) {
            flash('danger', 'All required onboarding documents must be verified before creating an employee account.');
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        $existingEmp = db()->prepare('SELECT id FROM employees WHERE email = ? LIMIT 1');
        $existingEmp->execute([strtolower(trim($applicant['email']))]);
        if ($existingEmp->fetch()) {
            flash('danger', 'An employee record already exists for this email address.');
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        [$ok, $msg, $empNo, $tempPw] = createEmployeeAccountFromApplicant($applicant, $_SESSION['user_id']);
        if ($ok) {
            db()->prepare('UPDATE applicants SET employee_account_created_at = NOW() WHERE id = ?')->execute([$id]);
            if ($progress['current_stage'] === 'documents_verified') {
                advanceOnboardingStage($id);
            }
            flash('success', "{$msg} Temporary password: {$tempPw}");
        } else {
            flash('danger', $msg);
        }
        redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
    }

    if ($action === 'complete_onboarding') {
        $empCheck = db()->prepare('SELECT id FROM employees WHERE email = ? LIMIT 1');
        $empCheck->execute([strtolower(trim($applicant['email']))]);
        if (!$empCheck->fetch()) {
            flash('danger', 'Employee account must be created before completing onboarding.');
            redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
        }
        if ($progress['current_stage'] === 'account_created') {
            advanceOnboardingStage($id);
        }
        securityLog('onboarding_completed_event', "applicant_id={$id}", $_SESSION['user_id']);
        flash('success', 'Onboarding completed. ' . e($applicant['first_name'] . ' ' . $applicant['last_name']) . ' is now an active employee.');
        redirect(BASE_URL . '/modules/onboarding/index.php?tab=completed');
    }

    flash('danger', 'Unknown action.');
    redirect(BASE_URL . '/modules/onboarding/applicant_view.php?id=' . $id);
}

$aStmt = db()->prepare('SELECT a.*, d.name AS department_name FROM applicants a LEFT JOIN departments d ON d.id = a.department_id WHERE a.id = ?');
$aStmt->execute([$id]);
$applicant = $aStmt->fetch();
if (!$applicant) {
    flash('danger', 'Applicant not found.');
    redirect(BASE_URL . '/modules/onboarding/index.php');
}

$progress = getOnboardingProgress($id);
$orientStmt = db()->prepare('SELECT * FROM orientation_schedules WHERE applicant_id = ?');
$orientStmt->execute([$id]);
$orientation = $orientStmt->fetch();
$docStatus = $progress ? onboardingDocumentStatus($id) : [];
$empEmail = strtolower(trim($applicant['email']));
$empExists = db()->prepare('SELECT id, employee_no FROM employees WHERE email = ? LIMIT 1');
$empExists->execute([$empEmail]);
$existingEmployee = $empExists->fetch();

$pageTitle = 'Onboarding - ' . e($applicant['first_name'] . ' ' . $applicant['last_name']);
$currentModule = 'onboarding';
require_once __DIR__ . '/../../includes/header.php';

$stages = onboardingStages();
$currentIdx = $progress ? array_search($progress['current_stage'], $stages) : -1;
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title"><?= e($applicant['first_name'] . ' ' . $applicant['last_name']) ?></h1>
        <p class="page-subtitle">
            <?= e($applicant['applicant_no']) ?> &mdash; <?= e($applicant['position_applied']) ?>
            &mdash; <?= statusBadge($applicant['status']) ?>
        </p>
    </div>
    <div class="btn-group">
        <a href="index.php" class="btn btn-outline">&larr; Back to Onboarding</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.05s">
    <h2>Onboarding Progress</h2>
    <?php if (!$progress): ?>
        <p>This applicant has not started onboarding yet.</p>
        <form method="post" style="margin-top:1rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="start_onboarding">
            <button type="submit" class="btn btn-primary">Start Onboarding</button>
        </form>
    <?php else: ?>
    <div style="display:flex;gap:0;flex-wrap:wrap;margin:1rem 0;">
        <?php foreach ($stages as $i => $stage): ?>
        <div style="flex:1;min-width:120px;text-align:center;padding:.75rem .5rem;background:<?= $i < $currentIdx ? '#d4edda' : ($i === $currentIdx ? '#e8f0fe' : '#f8f9fa') ?>;border:1px solid <?= $i <= $currentIdx ? '#4361ee' : '#dee2e6' ?>;border-radius:0;">
            <div style="font-size:1.1rem;margin-bottom:.25rem;"><?= $i < $currentIdx ? '&#10003;' : ($i === $currentIdx ? '&#9679;' : '&#9675;') ?></div>
            <div style="font-size:.7rem;font-weight:600;"><?= e(onboardingStageLabel($stage)) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php if ($progress): ?>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>1. Orientation Schedule</h2>
    <?php if ($orientation): ?>
    <div class="detail-grid">
        <div class="detail-item"><label>Date</label><span><?= formatDate($orientation['orientation_date']) ?></span></div>
        <div class="detail-item"><label>Time</label><span><?= date('g:i A', strtotime($orientation['orientation_time'])) ?></span></div>
        <div class="detail-item"><label>Location</label><span><?= e($orientation['location']) ?></span></div>
        <?php if ($orientation['venue']): ?>
        <div class="detail-item"><label>Venue</label><span><?= e($orientation['venue']) ?></span></div>
        <?php endif; ?>
        <div class="detail-item"><label>Status</label><span><?= $orientation['is_completed'] ? statusBadge('completed') : statusBadge('pending') ?></span></div>
    </div>
    <?php if (!$orientation['is_completed'] && onboardingStageReached($progress['current_stage'], 'orientation_scheduled') && !onboardingStageReached($progress['current_stage'], 'orientation_completed')): ?>
    <form method="post" style="margin-top:1rem;" onsubmit="return confirm('Mark orientation as completed?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="complete_orientation">
        <button type="submit" class="btn btn-primary">Mark Orientation Completed</button>
    </form>
    <?php endif; ?>
    <?php else: ?>
    <form method="post" style="margin-top:.5rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="schedule_orientation">
        <div class="form-grid">
            <div class="form-group"><label>Date *</label><input type="date" name="orientation_date" required min="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label>Time *</label><input type="time" name="orientation_time" required></div>
            <div class="form-group"><label>Location *</label><input type="text" name="location" required placeholder="e.g. Main Office"></div>
            <div class="form-group"><label>Venue</label><input type="text" name="venue" placeholder="e.g. Conference Room A"></div>
            <div class="form-group full-width"><label>Notes</label><textarea name="notes" rows="2" placeholder="Optional instructions..."></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary">Schedule Orientation</button>
    </form>
    <?php endif; ?>
</section>

<section class="panel fade-in-up" style="animation-delay:.15s">
    <h2>2. Orientation</h2>
    <?php if ($orientation && $orientation['is_completed']): ?>
        <p>Orientation completed on <?= formatDate($orientation['completed_at']) ?>. <?= statusBadge('completed') ?></p>
    <?php elseif ($orientation && !$orientation['is_completed']): ?>
        <p>Orientation is scheduled but not yet completed. <?= statusBadge('pending') ?></p>
    <?php else: ?>
        <p>Orientation has not been scheduled yet. <?= statusBadge('pending') ?></p>
    <?php endif; ?>
</section>

<section class="panel fade-in-up" style="animation-delay:.2s">
    <h2>3. Requirements / Documents</h2>
    <?php if (onboardingStageReached($progress['current_stage'], 'documents_submitted')): ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Required Document</th><th>Status</th><th>Details</th></tr></thead>
            <tbody>
                <?php foreach ($docStatus as $type => $info): ?>
                <?php if ($type === '_extra') continue; ?>
                <tr>
                    <td><strong><?= e($info['label']) ?></strong></td>
                    <td>
                        <?php if ($info['status'] === 'verified'): ?>
                            <?= statusBadge('verified') ?>
                        <?php elseif ($info['status'] === 'rejected'): ?>
                            <?= statusBadge('rejected') ?>
                        <?php elseif ($info['status'] === 'pending'): ?>
                            <?= statusBadge('pending') ?>
                        <?php else: ?>
                            <span class="badge badge-secondary">Not Submitted</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($info['document'] && $info['document']['file_path']): ?>
                            <small><?= e($info['document']['document_name']) ?></small>
                            <br><small>Uploaded <?= formatDate($info['document']['uploaded_at']) ?></small>
                            <?php if ($info['status'] === 'rejected' && $info['document']['rejection_reason']): ?>
                            <br><small style="color:#dc3545;">Reason: <?= e($info['document']['rejection_reason']) ?></small>
                            <?php endif; ?>
                        <?php else: ?>&mdash;<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border,#dee2e6)">
        <h3>Upload Document</h3>
        <form method="post" enctype="multipart/form-data" style="margin-top:.5rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_document">
            <div class="form-grid">
                <div class="form-group"><label>Document Type *</label>
                    <select name="document_type" required>
                        <option value="">-- Select --</option>
                        <option value="government_id">Government IDs (SSS, PhilHealth, Pag-IBIG, TIN)</option>
                        <option value="contract">Employment Contract</option>
                        <option value="diploma">Diploma / Transcript of Records</option>
                        <option value="medical_certificate">Medical Certificate</option>
                        <option value="nbi_clearance">NBI Clearance</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group"><label>Document Name *</label><input type="text" name="document_name" required placeholder="e.g. SSS ID, Diploma"></div>
                <div class="form-group"><label>File *</label><input type="file" name="document_file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>
            </div>
            <button type="submit" class="btn btn-primary">Upload Document</button>
        </form>
    </div>
    <?php else: ?>
    <p>Documents become available after orientation is completed.</p>
    <?php endif; ?>
</section>

<section class="panel fade-in-up" style="animation-delay:.25s">
    <h2>4. Document Verification</h2>
    <?php if (allRequiredDocumentsVerified($id)): ?>
        <p>All required documents have been verified. <?= statusBadge('verified') ?></p>
    <?php else: ?>
        <p>Upload and verify all required documents before proceeding.</p>
    <?php endif; ?>
    <?php
    $allDocs = db()->prepare('SELECT * FROM onboarding_documents WHERE applicant_id = ? ORDER BY uploaded_at DESC');
    $allDocs->execute([$id]);
    $docsList = $allDocs->fetchAll();
    ?>
    <?php if ($docsList): ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Document</th><th>Type</th><th>Uploaded</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($docsList as $doc): ?>
                <tr>
                    <td><?= e($doc['document_name']) ?></td>
                    <td><?= e(ucfirst(str_replace('_', ' ', $doc['document_type']))) ?></td>
                    <td><?= formatDate($doc['uploaded_at']) ?></td>
                    <td><?= statusBadge($doc['verification_status']) ?></td>
                    <td>
                        <?php if ($doc['verification_status'] === 'pending'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="verify_document">
                            <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
                            <input type="hidden" name="verification_status" value="verified">
                            <button type="submit" class="btn btn-sm btn-primary">Verify</button>
                        </form>
                        <form method="post" style="display:inline;margin-left:.25rem;" onsubmit="var r=prompt('Rejection reason:');if(!r){return false;}this.rejection_reason.value=r;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="verify_document">
                            <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
                            <input type="hidden" name="verification_status" value="rejected">
                            <input type="hidden" name="rejection_reason" value="">
                            <button type="submit" class="btn btn-sm btn-outline" style="color:#dc3545;border-color:#dc3545;">Reject</button>
                        </form>
                        <?php elseif ($doc['verification_status'] === 'rejected'): ?>
                            <small style="color:#dc3545;"><?= e($doc['rejection_reason'] ?? '') ?></small>
                        <?php else: ?>
                            <small>Verified</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p>No documents uploaded yet.</p>
    <?php endif; ?>
</section>

<section class="panel fade-in-up" style="animation-delay:.3s">
    <h2>5. Employee Account Creation</h2>
    <?php if ($existingEmployee): ?>
        <p>Employee account already created: <?= e($existingEmployee['employee_no']) ?> <?= statusBadge('completed') ?></p>
    <?php elseif ($progress['current_stage'] === 'documents_verified' || onboardingStageReached($progress['current_stage'], 'account_created')): ?>
        <?php if (allRequiredDocumentsVerified($id)): ?>
        <p>All required documents verified. You can now create the employee account.</p>
        <form method="post" style="margin-top:1rem;" onsubmit="return confirm('Create employee account for this applicant?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_employee_account">
            <div class="detail-grid" style="margin-bottom:1rem;">
                <div class="detail-item"><label>Name</label><span><?= e($applicant['first_name'] . ' ' . $applicant['last_name']) ?></span></div>
                <div class="detail-item"><label>Email</label><span><?= e($applicant['email']) ?></span></div>
                <div class="detail-item"><label>Position</label><span><?= e($applicant['position_applied']) ?></span></div>
            </div>
            <button type="submit" class="btn btn-primary">Create Employee Account</button>
        </form>
        <?php else: ?>
        <p>All required documents must be verified before creating an employee account.</p>
        <?php endif; ?>
    <?php else: ?>
        <p>Employee account creation will be available after documents are verified.</p>
    <?php endif; ?>
</section>

<section class="panel fade-in-up" style="animation-delay:.35s">
    <h2>6. Onboarding Completion</h2>
    <?php if ($progress['current_stage'] === 'onboarding_completed'): ?>
        <p>Onboarding is completed. <?= statusBadge('completed') ?></p>
    <?php elseif ($existingEmployee && $progress['current_stage'] === 'account_created'): ?>
        <p>Employee account has been created. You can now mark onboarding as completed.</p>
        <form method="post" style="margin-top:1rem;" onsubmit="return confirm('Complete onboarding for this applicant?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="complete_onboarding">
            <button type="submit" class="btn btn-primary">Complete Onboarding</button>
        </form>
    <?php else: ?>
        <p>Onboarding completion will be available after the employee account is created.</p>
    <?php endif; ?>
</section>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
