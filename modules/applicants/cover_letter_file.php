<?php
/**
 * Streams ONE uploaded applicant cover letter — only for authorized viewers:
 *   - HR / Manager accounts (recruitment reviewers), or
 *   - the logged-in applicant account that owns this application.
 * Employees and guests are never allowed. Paths are confined to
 * the project's /uploads directory; no direct web access to files.
 * Only applications that actually used the "upload" cover-letter method
 * have a file here; written cover letters are shown as text in view.php.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect(BASE_URL . '/public/jobs.php');
}

$stmt = db()->prepare('SELECT id, user_id, email, cover_letter_method, cover_letter_path, first_name, last_name FROM applicants WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$applicant = $stmt->fetch();

$uid = (int) $_SESSION['user_id'];
$isHRorManager = in_array(getUserRole(), ['hr', 'manager'], true);
$isOwner = $applicant
    && ((int) ($applicant['user_id'] ?? 0) === $uid);

if (!$applicant) {
    flash('danger', 'Application not found.');
    redirect($isHRorManager ? BASE_URL . '/modules/applicants/index.php' : BASE_URL . '/public/jobs.php');
}

if (!$isHRorManager && !$isOwner) {
    flash('danger', 'You do not have permission to view this cover letter.');
    redirect(BASE_URL . '/public/jobs.php');
}

if (($applicant['cover_letter_method'] ?? '') !== 'upload' || empty($applicant['cover_letter_path'])) {
    flash('warning', 'No uploaded cover letter was attached to this application.');
    redirect($isHRorManager ? BASE_URL . '/modules/applicants/view.php?id=' . $id : BASE_URL . '/public/jobs.php');
}

/* ---- resolve + confine the path --------------------------------------- */
$baseDir = dirname(__DIR__, 2); // project root
$uploadsRoot = realpath($baseDir . '/uploads');

// Stored values use the "uploads/APPxxxx_cl_xxxx.ext" convention. Normalize
// to a path under /uploads so API-style and bare filenames resolve too.
// Traversal is still blocked by the realpath() confinement check below.
$relative = str_replace('\\', '/', ltrim((string) $applicant['cover_letter_path'], '/'));
if (str_starts_with($relative, 'uploads/')) {
    $relative = substr($relative, strlen('uploads/'));
}
$relative = ltrim($relative, '/');
if (!$uploadsRoot) {
    http_response_code(404);
    exit('File storage is unavailable.');
}
$fullPath = realpath($baseDir . '/uploads/' . $relative);

if (
    !$fullPath
    || !str_starts_with($fullPath, $uploadsRoot . DIRECTORY_SEPARATOR)
    || !is_file($fullPath)
) {
    flash('danger', 'The cover letter file is no longer available.');
    redirect($isHRorManager ? BASE_URL . '/modules/applicants/view.php?id=' . $id : BASE_URL . '/public/jobs.php');
}

/* ---- stream ------------------------------------------------------------ */
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$contentTypes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
if (!isset($contentTypes[$ext])) {
    http_response_code(404);
    exit('Unsupported file type.');
}

$safeName = preg_replace('/[^\w.\-]/', '_', $applicant['last_name'] . '_' . $applicant['first_name']);

header('Content-Type: ' . $contentTypes[$ext]);
header('Content-Length: ' . (string) filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $safeName . '_CoverLetter.' . $ext . '"');
header('Cache-Control: private, no-store');
readfile($fullPath);
exit;