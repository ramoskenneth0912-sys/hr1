<?php
/**
 * REMOVE JOB POSTING — soft-delete endpoint for the Recruitment module.
 *
 * SECURITY
 *   - Requires an HR or Manager session (requireHRorManager → 403/redirect for
 *     anyone else).
 *   - POST only. GET requests are refused.
 *   - CSRF protected: the token is validated in constant time against the
 *     session value before any state change. AJAX failures return JSON;
 *     regular form posts get the standard flash + redirect behaviour.
 *   - The job id is validated as a positive integer and must exist. The soft
 *     delete is keyed by job id (never by title), so renames or similar titles
 *     cannot affect the wrong record.
 *   - Database errors are never surfaced: they are logged and a generic
 *     message is returned.
 *
 * SOFT DELETE (data safety)
 *   This intentionally does NOT delete the job_postings row nor any
 *   applicants/applications/interviews/screening records. It reuses the
 *   existing status field (status = 'inactive'), so removed postings stop
 *   appearing in active listings and in the Applicant Statistics position
 *   dropdown, while every existing applicant still references the original
 *   job by job_posting_id.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_log.php';
requireHRorManager();

$isAjax = strtoupper((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'XMLHTTPREQUEST';

function respondJson(int $status, array $payload): void
{
    if (!array_key_exists('success', $payload)) {
        $payload['success'] = $status >= 200 && $status < 300;
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload);
    exit;
}

function redirectBack(string $type, string $message): void
{
    flash($type, $message);
    redirect(BASE_URL . '/modules/recruitment/index.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if ($isAjax) {
        respondJson(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    redirectBack('danger', 'Invalid request method.');
}

// Model csrf_require() but return JSON for AJAX instead of an HTML redirect.
$sent = $_POST['csrf_token'] ?? null;
if (!is_string($sent) || !csrf_valid($sent)) {
    if ($isAjax) {
        respondJson(403, ['ok' => false, 'error' => 'Security check failed. Please refresh the page and try again.']);
    }
    flash('danger', 'Security check failed. The form was submitted from an unexpected context or your session expired. Please try again.');
    redirect(BASE_URL . '/modules/recruitment/index.php');
}

// Validate the job id. Never operate on the title.
$idInput = trim((string) ($_POST['id'] ?? ''));
if ($idInput === '' || !ctype_digit($idInput) || (int) $idInput <= 0) {
    if ($isAjax) {
        respondJson(400, ['ok' => false, 'error' => 'Invalid job posting ID.']);
    }
    redirectBack('danger', 'Invalid job posting ID.');
}
$jobId = (int) $idInput;

try {
    // Existence check (also guards IDOR: an id that isn't a real, manageable
    // job can never be removed).
    $stmt = db()->prepare('SELECT id, title, status FROM job_postings WHERE id = ?');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        if ($isAjax) {
            respondJson(404, ['ok' => false, 'error' => 'Job posting not found.']);
        }
        redirectBack('danger', 'Job posting not found.');
    }

    if ($job['status'] === 'inactive') {
        if ($isAjax) {
            respondJson(409, ['ok' => false, 'error' => 'This job posting has already been removed.']);
        }
        redirectBack('danger', 'This job posting has already been removed.');
    }

    // Soft-delete via the existing status field. Applicant/application/history
    // records are untouched and keep referencing job_posting_id as before.
    db()->prepare("UPDATE job_postings SET status = 'inactive', updated_at = NOW() WHERE id = ?")
        ->execute([$jobId]);
    securityLog('job_posting_removed', 'Job posting removed (soft delete): #' . $jobId . ' ' . ($job['title'] ?? ''));

    if ($isAjax) {
        respondJson(200, [
            'ok'      => true,
            'message' => 'Job posting removed successfully.',
            'id'      => $jobId,
            'title'   => $job['title'] ?? '',
        ]);
    }
    redirectBack('success', 'Job posting removed successfully.');
} catch (Throwable $e) {
    error_log('[job_remove] ' . $e->getMessage());
    if ($isAjax) {
        respondJson(500, ['ok' => false, 'error' => 'Unable to remove the job posting. Please try again later.']);
    }
    redirectBack('danger', 'Unable to remove the job posting. Please try again.');
}