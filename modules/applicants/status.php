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

// Server-side transition map — never trust the frontend to pick an arbitrary
// status. Each target status may only be reached from the documented source
// statuses; this enforces the recruitment workflow stages (initial screening →
// screening passed → exam → final interview → selected → hired).
//   - accepted / passed_screening / rejected : only from undecided (Pending)
//   - offered (Selected)                      : only from interview
//   - hired                                  : only from offered (Selected)
$transitions = [
    'accepted'          => ['new', 'screening', 'shortlisted'],
    'passed_screening'  => ['new', 'screening', 'shortlisted'],
    'rejected'          => ['new', 'screening', 'shortlisted'],
    'offered'           => ['interview'],
    'hired'             => ['offered'],
];

if ($id <= 0 || !isset($transitions[$newStatus])) {
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

// Enforce the server-side transition rule. This prevents manually crafted
// requests from skipping stages (e.g. flipping a Screening applicant straight
// to Selected/Hired, or moving an Accepted application to Rejected).
if (!in_array($oldStatus, $transitions[$newStatus], true)) {
    flash('danger', 'This applicant cannot be moved to that status from their current stage.');
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

// Send the Online Examination email only for accepted / passed screening, and
// only once (guard on acceptance_email_sent_at). Never on rejection.
$emailMessage = null;
if (in_array($newStatus, ['accepted', 'passed_screening'], true)
    && empty($applicant['acceptance_email_sent_at'])) {
    require_once __DIR__ . '/../../includes/mail.php';
    require_once __DIR__ . '/../../includes/exam.php';
    require_once __DIR__ . '/../../includes/security_log.php';

    $applicantName = trim($applicant['first_name'] . ' ' . $applicant['last_name']);

    // Ensure a secure, no-login exam access token exists (reuses the existing
    // opaque token + pending-request mechanism; no fake exam/HR3 URL).
    $token = ensureApplicantExamToken((int) $id, (int) ($_SESSION['user_id'] ?? 0));
    $sent = false;
    if ($token !== null) {
        $examAccessLink = (defined('BASE_URL') ? BASE_URL : '/HR1')
            . '/modules/applicant/exam_access.php?token=' . urlencode($token);
        $sent = sendApplicationAcceptedEmail(
            $applicant['email'],
            $applicantName,
            $applicant['position_applied'],
            $examAccessLink
        );
    }

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

$labels = applicationStatuses();
if ($newStatus === 'rejected') {
    flash('success', 'Application rejected. No acceptance email was sent, and this applicant can no longer be scheduled for an interview.');
} elseif ($newStatus === 'offered') {
    flash('success', 'Applicant marked as Selected. You can now move them to Hired.');
} elseif ($newStatus === 'hired') {
    flash('success', 'Applicant marked as Hired.');
} else {
    flash('success', 'Application accepted. Applicant can now proceed to interview scheduling.' . ($emailMessage ?? ''));
}

redirect(BASE_URL . '/modules/applicants/view.php?id=' . $id);
