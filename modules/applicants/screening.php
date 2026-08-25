<?php
/**
 * Run AI Screening for an applicant.
 * Called via POST from the applicant list or detail page.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('danger', 'Invalid request method.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}
csrf_require();

$applicantId = (int) ($_POST['applicant_id'] ?? 0);
$jobPostingId = !empty($_POST['job_posting_id']) ? (int) $_POST['job_posting_id'] : null;

if ($applicantId <= 0) {
    flash('danger', 'Invalid applicant ID.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}

require_once __DIR__ . '/../../includes/ai_screening.php';

$result = runAiScreening($applicantId, $jobPostingId);

if (isset($result['error'])) {
    flash('danger', $result['error']);
    redirect(BASE_URL . '/modules/applicants/view.php?id=' . $applicantId);
}

flash('success', 'AI screening completed. Recommendation: ' . $result['recommendation'] . ' (' . $result['overall_score'] . '% match)');
redirect(BASE_URL . '/modules/applicants/screening_view.php?id=' . $applicantId);
