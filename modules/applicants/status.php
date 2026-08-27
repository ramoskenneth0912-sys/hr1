<?php
/**
 * Screening status action for HR/Admin.
 *
 * Accepts a POST with applicant_id + status, validates authorization and the
 * status against a server-side whitelist, persists the change, and runs the
 * existing acceptance-email logic only when the applicant is accepted.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('danger', 'Invalid request method.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}
csrf_require();

$id = (int) ($_POST['applicant_id'] ?? 0);
$newStatus = trim((string) ($_POST['status'] ?? ''));

// Server-side whitelist — never trust the frontend to pick an arbitrary status.
$allowedStatuses = ['accepted', 'rejected', 'passed_screening'];

if ($id <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
    flash('danger', 'Invalid status change.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}

$stmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
$stmt->execute([$id]);
$applicant = $stmt->fetch();

if (!$applicant) {
    flash('danger', 'Applicant not found.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}

$oldStatus = $applicant['status'];

// Only undecided applications (Pending/Screening) may be screened. This
// prevents manually crafted requests from flipping an Accepted application to
// Rejected (or vice-versa) once a decision has been made.
$undecidedStatuses = ['new', 'screening', 'shortlisted'];
if (!in_array($oldStatus, $undecidedStatuses, true)) {
    flash('danger', 'This application has already been decided and can no longer be changed.');
    redirect(BASE_URL . '/modules/applicants/view.php?id=' . $id);
}

db()->prepare('UPDATE applicants SET status = ? WHERE id = ?')->execute([$newStatus, $id]);

// In-app notification to the applicant's account.
if ($oldStatus !== $newStatus) {
    $labels = applicationStatuses();
    notifyUser(
        applicantUserId($applicant),
        'Application update',
        'Your application for ' . $applicant['position_applied'] . ' is now "'
            . ($labels[$newStatus] ?? ucfirst($newStatus)) . '".',
        BASE_URL . '/modules/applicant/dashboard.php'
    );
}

// Send the existing acceptance email only for accepted / passed screening, and
// only once (guard on acceptance_email_sent_at). Never on rejection.
$emailMessage = null;
if (in_array($newStatus, ['accepted', 'passed_screening'], true)
    && empty($applicant['acceptance_email_sent_at'])) {
    require_once __DIR__ . '/../../includes/mail.php';
    require_once __DIR__ . '/../../includes/security_log.php';

    $applicantName = trim($applicant['first_name'] . ' ' . $applicant['last_name']);
    $sent = sendApplicationAcceptedEmail(
        $applicant['email'],
        $applicantName,
        $applicant['position_applied']
    );

    if ($sent) {
        db()->prepare('UPDATE applicants SET acceptance_email_sent_at = NOW() WHERE id = ?')
            ->execute([$id]);
        securityLog(
            'app_acceptance_email_sent',
            'applicant_id=' . $id . ' status=' . $newStatus,
            $_SESSION['user_id']
        );
    } else {
        securityLog(
            'app_acceptance_email_failed',
            'applicant_id=' . $id . ' status=' . $newStatus,
            $_SESSION['user_id']
        );
        $emailMessage = ' The acceptance email could not be sent, but the status was saved.';
    }
}

if ($newStatus === 'rejected') {
    flash('success', 'Application rejected. No acceptance email was sent, and this applicant can no longer be scheduled for an interview.');
} else {
    flash('success', 'Application accepted. Applicant can now proceed to interview scheduling.' . ($emailMessage ?? ''));
}

redirect(BASE_URL . '/modules/applicants/view.php?id=' . $id);
