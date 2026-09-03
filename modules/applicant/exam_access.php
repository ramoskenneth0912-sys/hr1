<?php
/**
 * Tokenized, self-serve examination access page.
 *
 * This is the DIRECT access point for an applicant who has NO HR1 applicant
 * account. HR1 emails the applicant an opaque assignment reference
 * (the `access_token`, format exm_...) in the examination link; the applicant
 * opens this page with that token and is shown their assigned examination.
 *
 * SECURITY
 *   - No login required — the opaque token is the credential of possession.
 *   - Only the assignment's `access_token` is accepted; raw DB ids, applicant
 *     ids, emails and API secrets are NEVER exposed or accepted.
 *   - Invalid / blank / non-matching tokens are shown a neutral "not found"
 *     message (no enumeration of which token is valid).
 *   - Per-IP rate limiting to slow down token brute-forcing.
 *   - No HR3 information is revealed to the applicant.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_headers.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/exam.php';

$token = trim((string) ($_GET['token'] ?? ''));
$assignment = null;
$invalid = false;
$rateLimited = false;
$beginError = '';

if ($token !== '') {
    $rlKey = 'exam_access:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    if (!webRateLimit($rlKey, 5, 900)) {
        $rateLimited = true;
    } else {
        $assignment = findAssignmentByToken($token);
        if (!$assignment) {
            // Block repeated invalid-token probing without penalising a
            // legitimate applicant who revisits a valid link.
            $invalid = true;
            webRateLimitRecord($rlKey);
        }
    }
}

// "Start Examination" — a CSRF-protected POST from the tokenized page.
// Validates the token server-side, verifies the assignment, marks begun_at
// and launches the HR3-provided examination URL (in a new tab).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'begin') {
    csrf_require();
    $tok = trim((string) ($_POST['token'] ?? ''));
    $out = ($tok !== '') ? beginExamAssignment($tok) : ['ok' => false, 'code' => 'not_found', 'message' => 'Examination not found.'];
    if ($out['ok']) {
        // Redirect to the provider examination URL after server-side validation
        // + begun_at/status update. The provider URL is HTTPS-only (open-redirect
        // guard) and is NOT embedded in any email.
        $target = $out['url'] ?? '';
        $parsed = parse_url($target);
        $isSafeHttps = $parsed !== false
            && isset($parsed['scheme'])
            && strtolower((string) $parsed['scheme']) === 'https'
            && isset($parsed['host']);
        if (!$isSafeHttps) {
            $beginError = 'The examination source could not be opened securely. Please contact HR.';
            $assignment = ($tok !== '') ? findAssignmentByToken($tok) : null;
        } else {
            header('Location: ' . $target);
            exit;
        }
    } else {
        $beginError = $out['message'];
        // Re-resolve the assignment so the panel reflects current state.
        $assignment = ($tok !== '') ? findAssignmentByToken($tok) : null;
    }
}

$pageTitle = 'Examination';
$bodyClass = 'auth-page';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="auth-card" style="max-width:640px;margin:3rem auto;">
    <div class="auth-brand">
        <img src="<?= BASE_URL ?>/assets/images/tri-m-logo.png" alt="TRI-M Global logo" style="max-height:56px;">
        <h1 style="margin:.5rem 0 0;">Online Examination</h1>
    </div>

    <?php if ($rateLimited): ?>
    <div class="panel" style="margin-top:1.5rem;">
        <p><strong>Too many attempts.</strong></p>
        <p style="color:var(--muted)">
            Please try again later. If you continue to have trouble, contact the Human Resources
            Department at TRI-M Global Logistics &amp; Trading Inc.
        </p>
    </div>
    <?php elseif ($invalid || !$assignment): ?>
    <div class="panel" style="margin-top:1.5rem;">
        <p><strong>Examination not found.</strong></p>
        <p style="color:var(--muted)">
            The examination link is invalid or has expired, or you may not have been assigned an examination yet.
            If you believe this is a mistake, please contact the Human Resources Department at
            TRI-M Global Logistics &amp; Trading Inc.
        </p>
    </div>
    <?php else: ?>
        <?php
        $examTitle   = $assignment['exam_title'];
        $position    = $assignment['job_title'] ?? $assignment['position_applied'];
        $jobCode     = $assignment['job_code'] ?? '';
        $status      = $assignment['status'];
        $instructions = $assignment['instructions'] ?? '';
        ?>

    <div class="panel" style="margin-top:1.5rem;">
        <h2><?= e($examTitle) ?></h2>
        <p><strong>Position:</strong> <?= e($position) ?><?= $jobCode ? ' (' . e($jobCode) . ')' : '' ?></p>
        <p><strong>Status:</strong> <?= statusBadge($status) ?></p>

        <?php if ($status === 'assigned'): ?>
        <div class="alert alert-info" style="margin-top:1rem;">
            Your online examination is available. Please complete it within the required period.
            Your result will be reviewed as part of the next stage of recruiting.
        </div>

        <?php if ($beginError !== ''): ?>
        <div class="alert alert-danger"><?= e($beginError) ?></div>
        <?php endif; ?>

        <?php if ($instructions !== ''): ?>
        <hr style="margin:1.25rem 0;border:none;border-top:1px solid var(--border,#e5e7eb);">
        <h3>Instructions</h3>
        <div class="pre-line" style="white-space:pre-wrap;color:var(--muted);"><?= e($instructions) ?></div>
        <?php endif; ?>

        <form method="post" action="exam_access.php?token=<?= urlencode($token) ?>" style="margin-top:1.5rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="begin">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <button type="submit" class="btn btn-primary btn-lg">Start Examination</button>
        </form>
        <p class="text-muted" style="font-size:.8rem;margin-top:.5rem;">
            Clicking Start will open your examination in a new window. Complete it within the required period.
        </p>

        <p class="text-muted" style="font-size:.85rem;margin-top:1.25rem;">
            After completing the examination, the result will be reviewed as part of the next stage of recruitment.
            The Human Resources Department will contact you regarding the outcome.
        </p>
        <?php elseif ($status === 'in_progress'): ?>
        <p style="margin-top:1rem;">
            You have already started this examination. If it was interrupted, you can resume it.
        </p>
        <form method="post" action="exam_access.php?token=<?= urlencode($token) ?>" style="margin-top:1rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="begin">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <button type="submit" class="btn btn-primary btn-lg">Resume Examination</button>
        </form>
        <?php elseif ($status === 'completed'): ?>
        <p class="text-muted" style="margin-top:1rem;">
            This examination has been completed. The result will be reviewed as part of the next stage
            of recruitment. The Human Resources Department will contact you regarding the outcome.
        </p>
        <?php else: ?>
        <p class="text-muted" style="margin-top:1rem;">
            This examination is no longer available for access. Please contact the Human Resources
            Department at TRI-M Global Logistics &amp; Trading Inc. if you have any questions.
        </p>
        <?php endif; ?>
    </div>

    <p class="text-muted" style="font-size:.8rem;text-align:center;margin-top:1.5rem;">
        TRI-M Global Logistics &amp; Trading Inc. &middot; Human Resources Department
    </p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
