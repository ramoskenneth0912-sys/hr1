<?php
/**
 * Remove an applicant's application and its associated resume/CV.
 *
 * HR/Manager only, POST only, CSRF-protected. The applicants row IS the
 * application record in HR1, so removal deletes the applicant's row together
 * with every record that is DIRECTLY associated with that application through
 * HR1's own FK relationships (ai_screening, exam_assignments ->
 * attempts/results/answers, interviews, onboarding_documents/progress,
 * orientation_schedules, employee_onboarding links). User accounts, employees,
 * job postings, departments and every other applicant are never touched.
 *
 * A hired applicant (status 'hired' or employee account created via
 * onboarding) is intentionally NOT removable from this screen — that
 * application owns employee + user records that must be preserved.
 *
 * File handling: the stored resume_path / file_path is resolved with a
 * realpath() containment check restricted to the project /uploads directory.
 * No browser-submitted path is ever trusted (only applicant_id is read from
 * the request). Files are unlinked ONLY after the DB transaction commits and
 * ONLY when no other record still references the same physical file.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_log.php';
requireLogin();

// Authorization: HR/Admin only. Build a real HTTP 403 (no state change) for
// any authenticated-but-unauthorized account (e.g. employee / applicant) that
// calls this endpoint directly. Guests keep the app-standard login redirect.
if (!isHRorManager()) {
    http_response_code(403);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    flash('danger', 'Invalid request method.');
    redirect(BASE_URL . '/modules/applicants/index.php');
}
csrf_require();

$id = (int) ($_POST['applicant_id'] ?? 0);

// Re-validate the redirect context so a tampered tab/filter can never inject
// arbitrary query values. Whitelists mirror modules/applicants/index.php.
$tab = (string) ($_POST['tab'] ?? 'all');
if (!in_array($tab, ['all', 'new', 'screening', 'passed', 'exam', 'exam_results', 'final', 'rejected'], true)) {
    $tab = 'all';
}
$q        = trim((string) ($_POST['q'] ?? ''));
$position = trim((string) ($_POST['position'] ?? ''));
$match    = in_array($_POST['match'] ?? '', ['strong', 'moderate', 'low'], true) ? $_POST['match'] : '';
$sort     = in_array($_POST['sort'] ?? '', ['match_high', 'match_low'], true) ? $_POST['sort'] : '';
$qs = [];
if ($q !== '')             $qs['q'] = $q;
if ($position !== '')       $qs['position'] = $position;
if ($match !== '')          $qs['match'] = $match;
if ($sort !== '')           $qs['sort'] = $sort;
$back = BASE_URL . '/modules/applicants/index.php?tab=' . urlencode($tab);
if (!empty($qs)) {
    $back .= '&' . http_build_query($qs);
}

if ($id <= 0) {
    flash('danger', 'Unable to remove the applicant. No data was deleted.');
    redirect($back);
}

$stmt = db()->prepare('SELECT * FROM applicants WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$applicant = $stmt->fetch();

if (!$applicant) {
    flash('danger', 'The applicant no longer exists. No data was deleted.');
    redirect($back);
}

// Safety guard: never remove an application that owns an employee/system
// account. Preserves employees, user accounts, onboarding and all related
// historical records for people who were converted into employees.
if ((string) ($applicant['status'] ?? '') === 'hired' || !empty($applicant['employee_account_created_at'])) {
    flash('danger', 'Unable to remove the applicant. This application is linked to a created employee account and cannot be removed.');
    redirect($back);
}

/** Resolve a stored path to an absolute file path confined to /uploads, or null. */
function app_rm_abs_path(string $stored): ?string
{
    $baseDir    = dirname(__DIR__, 2);
    $uploadsRoot = realpath($baseDir . '/uploads');
    if (!$uploadsRoot) {
        return null;
    }

    $relative = str_replace('\\', '/', ltrim($stored, '/'));
    if (str_starts_with($relative, 'uploads/')) {
        $relative = substr($relative, strlen('uploads/'));
    }
    $relative = ltrim($relative, '/');
    if ($relative === '' || $relative === '.') {
        return null;
    }

    // Explicit traversal rejection before touching the filesystem.
    if (
        str_contains($relative, '/../') || str_contains($relative, '../')
        || str_starts_with($relative, '..')
    ) {
        return null;
    }

    $full = realpath($baseDir . '/uploads/' . $relative);
    if (!$full || !str_starts_with($full, $uploadsRoot . DIRECTORY_SEPARATOR) || is_dir($full)) {
        return null;
    }
    return $full;
}

/** How many records still reference the physical file at $abs (all tables). */
function app_rm_ref_count(string $abs): int
{
    $paths = [];
    foreach (db()->query('SELECT resume_path FROM applicants WHERE resume_path IS NOT NULL') as $r) {
        $paths[] = (string) $r['resume_path'];
    }
    foreach (db()->query('SELECT file_path FROM onboarding_documents WHERE file_path IS NOT NULL') as $r) {
        $paths[] = (string) $r['file_path'];
    }
    $count = 0;
    foreach ($paths as $p) {
        $a = app_rm_abs_path($p);
        if ($a !== null && $a === $abs) {
            $count++;
        }
    }
    return $count;
}

/** Delete the DB row that stored $abs, but only if it is still safe/unreferenced. */
function app_rm_remove_file_safe(string $abs): void
{
    $baseDir    = dirname(__DIR__, 2);
    $uploadsRoot = realpath($baseDir . '/uploads');
    if (!$uploadsRoot || !str_starts_with($abs, $uploadsRoot . DIRECTORY_SEPARATOR)) {
        return;
    }
    if (app_rm_ref_count($abs) > 0) {
        return; // another record still references this physical file — keep it.
    }
    if (is_file($abs)) {
        if (!@unlink($abs)) {
            error_log('applicant remove: failed to unlink ' . $abs);
        }
    }
    // File already missing → DB cleanup already done; continue safely.
}

// Collect every uploaded file associated with this application BEFORE the
// transaction removes the rows that would hide their paths from us.
$files = [];
if (!empty($applicant['resume_path'])) {
    $files[] = (string) $applicant['resume_path'];
}
$docStmt = db()->prepare('SELECT file_path FROM onboarding_documents WHERE applicant_id = ?');
$docStmt->execute([$id]);
foreach ($docStmt->fetchAll() as $d) {
    if (!empty($d['file_path'])) {
        $files[] = (string) $d['file_path'];
    }
}
$absFiles = [];
foreach ($files as $f) {
    $a = app_rm_abs_path($f);
    if ($a !== null) {
        $absFiles[$a] = true;
    }
}
$absFiles = array_keys($absFiles);

$pdo = db();

try {
    $pdo->beginTransaction();

    // Records directly associated with this application only. Order matters:
    // child rows are removed through the app's own FK cascade (exam_assignments
    // → exam_attempts → exam_results/exam_answers) and the remaining explicit
    // deletes target exactly THIS applicant_id.
    $pdo->prepare('DELETE FROM exam_assignments WHERE applicant_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM ai_screening WHERE applicant_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM interviews WHERE applicant_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM onboarding_documents WHERE applicant_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM onboarding_progress WHERE applicant_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM orientation_schedules WHERE applicant_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM employee_onboarding WHERE applicant_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM applicants WHERE id = ?')->execute([$id]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('applicant remove failed (applicant_id=' . $id . '): ' . $e->getMessage());
    flash('danger', 'Unable to remove the applicant. No data was deleted.');
    redirect($back);
}

// Physical files are removed only after the DB commit succeeded, confined to
// /uploads, and only when no other record still references the same file.
foreach ($absFiles as $abs) {
    app_rm_remove_file_safe($abs);
}

securityLog(
    'applicant_application_removed',
    'applicant_id=' . $id
        . ' applicant_no=' . ($applicant['applicant_no'] ?? '')
        . ' position=' . ($applicant['position_applied'] ?? '')
        . ' resume=' . ($applicant['resume_path'] ?? '')
        . ' files=' . count($absFiles),
    (int) ($_SESSION['user_id'] ?? 0)
);

flash('success', 'Applicant application and associated resume/CV removed successfully.');
redirect($back);