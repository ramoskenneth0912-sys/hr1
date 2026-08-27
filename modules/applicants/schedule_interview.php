<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('danger', 'Invalid applicant ID.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}

// Only Accepted / Passed Screening applicants may be scheduled.
$stmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
$stmt->execute([$id]);
$applicant = $stmt->fetch();

if (!$applicant) {
    flash('danger', 'Applicant not found.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}

if (!in_array($applicant['status'], ['accepted', 'passed_screening'], true)) {
    flash('warning', 'Only Accepted or Passed Screening applicants can be scheduled for an interview.');
    redirect(BASE_URL . '/modules/applicants/view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $interviewDate = trim($_POST['interview_date'] ?? '');
    $interviewTime = trim($_POST['interview_time'] ?? '');

    if ($interviewDate === '') {
        flash('danger', 'Interview date is required.');
        redirect(BASE_URL . '/modules/applicants/schedule_interview.php?id=' . $id);
    }
    if ($interviewTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $interviewTime)) {
        flash('danger', 'Interview time is invalid.');
        redirect(BASE_URL . '/modules/applicants/schedule_interview.php?id=' . $id);
    }

    // Combine date + time into the legacy datetime column (defaults time to 09:00 if none provided).
    $timeForDatetime = $interviewTime !== '' ? $interviewTime : '09:00:00';
    $combinedDate = $interviewDate . ' ' . $timeForDatetime;

    // Prevent duplicate active interviews for the same application.
    $dup = db()->prepare(
        "SELECT id FROM interviews
         WHERE (application_id = ? OR (application_id IS NULL AND applicant_id = ?))
           AND status IN ('scheduled','rescheduled')
         LIMIT 1"
    );
    $dup->execute([$id, $id]);
    if ($dup->fetch()) {
        flash('danger', 'This applicant already has an active scheduled interview. Please complete or cancel it before scheduling a new one.');
        redirect(BASE_URL . '/modules/applicants/view.php?id=' . $id);
    }

    // Once an interview has been completed, the interview stage is terminal —
    // do not allow a new interview to be scheduled that would overwrite/skip it.
    $done = db()->prepare(
        "SELECT id FROM interviews
         WHERE (application_id = ? OR (application_id IS NULL AND applicant_id = ?))
           AND status = 'completed'
         LIMIT 1"
    );
    $done->execute([$id, $id]);
    if ($done->fetch()) {
        flash('danger', 'This applicant has already completed an interview and can no longer be scheduled for a new one.');
        redirect(BASE_URL . '/modules/applicants/view.php?id=' . $id);
    }

    $insert = db()->prepare(
        'INSERT INTO interviews
            (applicant_id, application_id, job_posting_id, interview_date, interview_time,
             interviewer, location, company_name, company_address,
             additional_instructions, internal_notes, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        $id,
        $id,
        $applicant['job_posting_id'] ?? null,
        $combinedDate,
        $interviewTime !== '' ? $interviewTime : null,
        trim($_POST['interviewer'] ?? ''),
        trim($_POST['location'] ?? ''),
        trim($_POST['company_name'] ?? ''),
        trim($_POST['company_address'] ?? ''),
        trim($_POST['additional_instructions'] ?? ''),
        trim($_POST['internal_notes'] ?? ''),
        'scheduled',
    ]);

    // Interview saved first. Now send the invitation email to the applicant's
    // registered address (from the database) using the existing SMTP system.
    $interviewId = (int) db()->lastInsertId();

    require_once __DIR__ . '/../../includes/mail.php';
    require_once __DIR__ . '/../../includes/security_log.php';

    // Prevent duplicate invitation emails for the same interview.
    $sentCheck = db()->prepare(
        "SELECT id FROM security_log
         WHERE event_type = 'interview_invite_email_sent' AND details LIKE ? LIMIT 1"
    );
    $sentCheck->execute(['%interview_id=' . $interviewId . ' %']);
    $alreadySent = (bool) $sentCheck->fetch();

    if ($alreadySent) {
        flash('success', 'Interview scheduled successfully. Notification email already sent.');
        redirect(BASE_URL . '/modules/applicants/view.php?id=' . $id);
    }

    $applicantName = trim($applicant['first_name'] . ' ' . $applicant['last_name']);
    $invitationDate = date('F j, Y', strtotime($combinedDate));
    $invitationTime = $interviewTime !== '' ? date('g:i A', strtotime($combinedDate)) : 'To be confirmed';

    $emailSent = sendInterviewInvitationEmail(
        $applicant['email'],
        $applicantName,
        $applicant['position_applied'],
        [
            'date'                  => $invitationDate,
            'time'                  => $invitationTime,
            'location'              => trim($_POST['location'] ?? ''),
            'company_address'       => trim($_POST['company_address'] ?? ''),
            'interviewer'           => trim($_POST['interviewer'] ?? ''),
            'additional_instructions' => trim($_POST['additional_instructions'] ?? ''),
        ]
    );

    if ($emailSent) {
        securityLog(
            'interview_invite_email_sent',
            'applicant_id=' . $id . ' interview_id=' . $interviewId . ' status=scheduled',
            $_SESSION['user_id']
        );
        flash('success', 'Interview scheduled successfully. Notification email sent.');
    } else {
        // Email failed — the interview record is kept intact.
        securityLog(
            'interview_invite_email_failed',
            'applicant_id=' . $id . ' interview_id=' . $interviewId . ' status=scheduled',
            $_SESSION['user_id']
        );
        flash('warning', 'Interview scheduled successfully, but the notification email could not be sent. Please check the email configuration or retry the notification.');
    }
    redirect(BASE_URL . '/modules/applicants/view.php?id=' . $id);
}

$pageTitle = 'Schedule Interview — ' . $applicant['first_name'] . ' ' . $applicant['last_name'];
$currentModule = 'applicants';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Schedule Interview</h1>
        <p class="page-subtitle"><?= e($applicant['applicant_no'] . ' — ' . $applicant['first_name'] . ' ' . $applicant['last_name']) ?></p>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline">← Back</a>
</div>

<form method="post" class="form-panel fade-in-up" style="animation-delay:.1s">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="form-group">
            <label for="interview_date">Interview Date *</label>
            <input type="date" id="interview_date" name="interview_date" required>
        </div>
        <div class="form-group">
            <label for="interview_time">Interview Time</label>
            <input type="time" id="interview_time" name="interview_time">
        </div>
        <div class="form-group">
            <label for="location">Interview Location</label>
            <input type="text" id="location" name="location" placeholder="e.g. Main Office — Conference Room">
        </div>
        <div class="form-group">
            <label for="company_name">Company Name</label>
            <input type="text" id="company_name" name="company_name" value="TRI-M Global Logistics &amp; Trading Inc.">
        </div>
        <div class="form-group">
            <label for="company_address">Company Address</label>
            <input type="text" id="company_address" name="company_address">
        </div>
        <div class="form-group">
            <label for="interviewer">Interviewer / HR Representative</label>
            <input type="text" id="interviewer" name="interviewer">
        </div>
        <div class="form-group full-width">
            <label for="additional_instructions">Additional Instructions</label>
            <textarea id="additional_instructions" name="additional_instructions" rows="3"></textarea>
        </div>
        <div class="form-group full-width">
            <label for="internal_notes">Internal Notes <small style="color:var(--muted);font-weight:normal;">(Private — HR only, never shown to applicants)</small></label>
            <textarea id="internal_notes" name="internal_notes" rows="3"></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Schedule Interview</button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
