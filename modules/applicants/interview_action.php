<?php
/**
 * Interview lifecycle actions for HR/Admin (centralized Interview History).
 *
 * Supported actions:
 *   - reschedule : move current schedule to previous_*, set new schedule, status -> rescheduled
 *   - complete   : status -> completed
 *   - no_show    : status -> no_show
 *   - cancel     : status -> cancelled
 *
 * Authorization is verified server-side (HR/Admin only). All state changes
 * require a valid CSRF token. Only Scheduled / Rescheduled interviews can be
 * changed; Completed / Cancelled / No Show records are terminal (View only).
 */
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

function redirectBack(): void
{
    redirect(BASE_URL . '/modules/applicants/interview_history.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();

    $id      = (int) ($_POST['id'] ?? 0);
    $action  = trim((string) ($_POST['action'] ?? ''));
    $allowed = ['reschedule', 'complete', 'no_show', 'cancel'];

    if ($id <= 0 || !in_array($action, $allowed, true)) {
        flash('danger', 'Invalid interview action.');
        redirectBack();
    }

    $stmt = db()->prepare('SELECT * FROM interviews WHERE id = ?');
    $stmt->execute([$id]);
    $interview = $stmt->fetch();

    if (!$interview) {
        flash('danger', 'Interview record not found.');
        redirectBack();
    }

    // Only Scheduled / Rescheduled interviews may be changed.
    if (!in_array($interview['status'], ['scheduled', 'rescheduled'], true)) {
        flash('danger', 'This interview can no longer be modified.');
        redirectBack();
    }

    // Load applicant for notifications / logging.
    $aStmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
    $aStmt->execute([$interview['applicant_id']]);
    $applicant = $aStmt->fetch();

    require_once __DIR__ . '/../../includes/security_log.php';

    if ($action === 'cancel') {
        db()->prepare("UPDATE interviews SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
            ->execute([$id]);
        securityLog('interview_cancelled', 'interview_id=' . $id . ' applicant_id=' . $interview['applicant_id'], $_SESSION['user_id'] ?? null);
        notifyUser(applicantUserId($applicant), 'Interview cancelled', 'Your interview has been cancelled.', BASE_URL . '/modules/applicant/dashboard.php');
        flash('success', 'Interview cancelled.');
        redirectBack();
    }

    if ($action === 'complete') {
        db()->prepare("UPDATE interviews SET status = 'completed', updated_at = NOW() WHERE id = ?")
            ->execute([$id]);
        securityLog('interview_completed', 'interview_id=' . $id . ' applicant_id=' . $interview['applicant_id'], $_SESSION['user_id'] ?? null);
        notifyUser(applicantUserId($applicant), 'Interview completed', 'Your interview has been marked as completed.', BASE_URL . '/modules/applicant/dashboard.php');
        flash('success', 'Interview marked as completed.');
        redirectBack();
    }

    if ($action === 'no_show') {
        db()->prepare("UPDATE interviews SET status = 'no_show', updated_at = NOW() WHERE id = ?")
            ->execute([$id]);
        securityLog('interview_no_show', 'interview_id=' . $id . ' applicant_id=' . $interview['applicant_id'], $_SESSION['user_id'] ?? null);
        notifyUser(applicantUserId($applicant), 'No show', 'You were marked as a no show for your interview.', BASE_URL . '/modules/applicant/dashboard.php');
        flash('success', 'Applicant marked as no show.');
        redirectBack();
    }

    if ($action === 'reschedule') {
        $newDate = trim((string) ($_POST['interview_date'] ?? ''));
        $newTime = trim((string) ($_POST['interview_time'] ?? ''));
        $newLocation = trim((string) ($_POST['location'] ?? ''));

        if ($newDate === '') {
            flash('danger', 'The new interview date is required.');
            redirect(BASE_URL . '/modules/applicants/interview_action.php?id=' . $id . '&action=reschedule');
        }
        if ($newTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newTime)) {
            flash('danger', 'The new interview time is invalid.');
            redirect(BASE_URL . '/modules/applicants/interview_action.php?id=' . $id . '&action=reschedule');
        }

        $timeForDatetime = $newTime !== '' ? $newTime : ($interview['interview_time'] ?: '09:00:00');
        $combinedDate = $newDate . ' ' . $timeForDatetime;

        // Move the current schedule into the previous_* columns (same record,
        // no duplicate rows), then apply the new schedule.
        $upd = db()->prepare(
            "UPDATE interviews
                SET previous_date      = interview_date,
                    previous_time      = interview_time,
                    previous_location  = location,
                    interview_date     = ?,
                    interview_time     = ?,
                    location           = ?,
                    status             = 'rescheduled',
                    updated_at         = NOW()
              WHERE id = ?"
        );
        $upd->execute([
            $combinedDate,
            $newTime !== '' ? $newTime : ($interview['interview_time'] ?: null),
            $newLocation,
            $id,
        ]);

        securityLog('interview_rescheduled', 'interview_id=' . $id . ' applicant_id=' . $interview['applicant_id'], $_SESSION['user_id'] ?? null);
        $when = date('F j, Y \a\t g:i A', strtotime($combinedDate));
        notifyUser(applicantUserId($applicant), 'Interview rescheduled', 'Your interview for ' . ($applicant['position_applied'] ?? 'the position') . ' has been rescheduled to ' . $when . '.', BASE_URL . '/modules/applicant/dashboard.php');
        flash('success', 'Interview rescheduled. The previous schedule was preserved in history.');
        redirectBack();
    }

    flash('danger', 'Unsupported action.');
    redirectBack();
}

// ============ GET: render reschedule form ============

$id = (int) ($_GET['id'] ?? 0);
$action = trim((string) ($_GET['action'] ?? ''));

if ($id <= 0 || $action !== 'reschedule') {
    flash('danger', 'Invalid request.');
    redirectBack();
}

$stmt = db()->prepare('SELECT * FROM interviews WHERE id = ?');
$stmt->execute([$id]);
$interview = $stmt->fetch();

if (!$interview) {
    flash('danger', 'Interview record not found.');
    redirectBack();
}
if (!in_array($interview['status'], ['scheduled', 'rescheduled'], true)) {
    flash('danger', 'This interview can no longer be rescheduled.');
    redirectBack();
}

$aStmt = db()->prepare("SELECT i.*, a.first_name, a.last_name, a.applicant_no, a.position_applied, j.title AS job_title
                        FROM interviews i
                        JOIN applicants a ON i.applicant_id = a.id
                        LEFT JOIN job_postings j ON i.job_posting_id = j.id
                        WHERE i.id = ?");
$aStmt->execute([$id]);
$row = $aStmt->fetch();
$applicantName = $row['first_name'] . ' ' . $row['last_name'];

$pageTitle = 'Reschedule Interview';
$currentModule = 'applicants';
require_once __DIR__ . '/../../includes/header.php';

$currentTimeVal = $row['interview_time'] ? substr($row['interview_time'], 0, 5) : '';
?>
<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Reschedule Interview</h1>
        <p class="page-subtitle"><?= e($row['applicant_no'] . ' — ' . $applicantName) ?></p>
    </div>
    <a href="interview_history.php" class="btn btn-outline">← Back to Interview History</a>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <div class="detail-grid">
        <div class="detail-item"><label>Current Status</label><span><?= statusBadge($row['status']) ?></span></div>
        <div class="detail-item"><label>Current Schedule</label><span><?= date('M d, Y', strtotime($row['interview_date'])) ?> · <?= $row['interview_time'] ? date('g:i A', strtotime($row['interview_time'])) : '—' ?> · <?= e($row['location'] ?: '—') ?></span></div>
    </div>
</section>

<form method="post" action="interview_action.php" class="form-panel fade-in-up" style="animation-delay:.15s">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="action" value="reschedule">
    <div class="form-grid">
        <div class="form-group">
            <label for="interview_date">New Interview Date *</label>
            <input type="date" id="interview_date" name="interview_date" required>
        </div>
        <div class="form-group">
            <label for="interview_time">New Interview Time</label>
            <input type="time" id="interview_time" name="interview_time" value="<?= e($currentTimeVal) ?>">
        </div>
        <div class="form-group full-width">
            <label for="location">New Location</label>
            <input type="text" id="location" name="location" value="<?= e($row['location'] ?? '') ?>" placeholder="e.g. Main Office — Conference Room">
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Reschedule</button>
        <a href="interview_history.php" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
