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

// The server-side transition map lives in includes/functions.php
// (applicationStatusTransitions()). Each target status may only be reached
// from the documented source statuses; this enforces the recruitment workflow
// stages (initial screening → screening passed → final interview → selected →
// hired). 'screening'/'interview' are intentionally absent: they are advanced
// only by their gated modules (ai_screening.php, interview_create.php).

if ($id <= 0 || !isset(applicationStatusTransitions()[$newStatus])) {
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
if (!canTransitionApplicationStatus((string) $oldStatus, $newStatus)) {
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

// The Online Examination email is NOT sent when HR1 accepts or passes a
// CV/resume. That email is triggered exclusively when HR3 has actually
// assigned the requested examination to this specific applicant (see
// assignExamToApplicant() in includes/exam.php). Accepting/passing screening
// in HR1 only records the status and the in-app status notification above.

$labels = applicationStatuses();
if ($newStatus === 'rejected') {
    flash('success', 'Application rejected. This applicant can no longer be scheduled for an interview.');
} elseif ($newStatus === 'offered') {
    flash('success', 'Applicant marked as Selected. You can now move them to Hired.');
} elseif ($newStatus === 'hired') {
    flash('success', 'Applicant marked as Hired.');
} else {
    flash('success', 'Application accepted. Applicant can now proceed to interview scheduling.');
}

redirect(BASE_URL . '/modules/applicants/view.php?id=' . $id);
