<?php
/**
 * Centralized Interview History for HR/Admin.
 *
 * Shows interview records for ALL applicants in one place, with status
 * filters (Scheduled / Rescheduled / Completed / Cancelled / No Show) and
 * per-record lifecycle actions. HR/Admin only — verified server-side.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$allowedStatuses = ['scheduled', 'rescheduled', 'completed', 'cancelled', 'no_show'];
$filter = trim((string) ($_GET['filter'] ?? ''));
if ($filter !== '' && !in_array($filter, $allowedStatuses, true)) {
    $filter = '';
}

$sql = 'SELECT i.*, a.applicant_no, a.first_name, a.last_name, a.position_applied,
               j.title AS job_title
        FROM interviews i
        JOIN applicants a ON i.applicant_id = a.id
        LEFT JOIN job_postings j ON i.job_posting_id = j.id';
$params = [];

if ($filter !== '') {
    $sql .= ' WHERE i.status = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY i.interview_date DESC, i.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$interviews = $stmt->fetchAll();

$pageTitle = 'Interview History';
$currentModule = 'applicants';
require_once __DIR__ . '/../../includes/header.php';

$countByStatus = [];
$agg = db()->query(
    "SELECT status, COUNT(*) AS c FROM interviews GROUP BY status"
)->fetchAll();
foreach ($agg as $a) {
    $countByStatus[$a['status']] = (int) $a['c'];
}

$fmtTime = function ($iv): string {
    if (!empty($iv['interview_time'])) {
        return date('g:i A', strtotime($iv['interview_time']));
    }
    return !empty($iv['interview_date']) ? date('g:i A', strtotime($iv['interview_date'])) : '—';
};
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Interview History</h1>
        <p class="page-subtitle">Centralized records of all applicant interviews</p>
    </div>
    <div class="btn-group">
        <a href="index.php" class="btn btn-outline">← Back to Applicants</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <div class="panel-header">
        <h2>Filter by Status</h2>
        <div class="btn-group" style="flex-wrap:wrap;">
            <a href="interview_history.php" class="btn btn-sm <?= $filter === '' ? 'btn-primary' : 'btn-outline' ?>">All <?= array_sum($countByStatus) > 0 ? '(' . array_sum($countByStatus) . ')' : '' ?></a>
            <?php foreach ($allowedStatuses as $s): ?>
            <a href="interview_history.php?filter=<?= urlencode($s) ?>"
               class="btn btn-sm <?= $filter === $s ? 'btn-primary' : 'btn-outline' ?>">
                <?= e(ucfirst(str_replace('_', ' ', $s))) ?> <?= ($countByStatus[$s] ?? 0) ? '(' . (int) ($countByStatus[$s] ?? 0) . ')' : '' ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="panel fade-in-up" style="animation-delay:.15s">
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Applicant</th>
                <th>Job Position</th>
                <th>Interview Date</th>
                <th>Time</th>
                <th>Location</th>
                <th>Interviewer</th>
                <th>Status</th>
                <th>Last Updated</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($interviews)): ?>
            <tr><td colspan="9" class="empty">
                <?= $filter !== '' ? 'No ' . e(ucfirst(str_replace('_', ' ', $filter))) . ' interviews found.' : 'No interview records yet.' ?>
            </td></tr>
            <?php else: foreach ($interviews as $row):
                $applicantName = $row['first_name'] . ' ' . $row['last_name'];
                $isActive = in_array($row['status'], ['scheduled', 'rescheduled'], true);
            ?>
            <tr>
                <td>
                    <a href="view.php?id=<?= (int) $row['applicant_id'] ?>">
                        <?= e($row['applicant_no']) ?> — <?= e($applicantName) ?>
                    </a>
                </td>
                <td><?= e($row['position_applied'] ?: ($row['job_title'] ?? '—')) ?></td>
                <td><?= date('M d, Y', strtotime($row['interview_date'])) ?></td>
                <td><?= $fmtTime($row) ?></td>
                <td>
                    <?= e($row['location'] ?: '—') ?>
                    <?php if ($row['status'] === 'rescheduled' && !empty($row['previous_location']) && $row['previous_location'] !== $row['location']): ?>
                    <div class="text-muted" style="font-size:.78rem;">Prev: <?= e($row['previous_location']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e($row['interviewer'] ?: '—') ?></td>
                <td><?= statusBadge($row['status']) ?></td>
                <td><?= date('M d, Y h:i A', strtotime($row['updated_at'])) ?></td>
                <td class="actions">
                    <div class="btn-group" style="flex-wrap:nowrap;">
                        <a href="interview_view.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline">View</a>
                        <?php if ($isActive): ?>
                        <a href="interview_action.php?id=<?= (int) $row['id'] ?>&action=reschedule" class="btn btn-sm btn-primary">Reschedule</a>
                        <form method="post" action="interview_action.php" style="margin:0;display:inline;"
                              onsubmit="return confirm('Mark this interview as completed?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="action" value="complete">
                            <button type="submit" class="btn btn-sm btn-outline">Complete</button>
                        </form>
                        <form method="post" action="interview_action.php" style="margin:0;display:inline;"
                              onsubmit="return confirm('Mark this applicant as no show?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="action" value="no_show">
                            <button type="submit" class="btn btn-sm btn-outline">No Show</button>
                        </form>
                        <form method="post" action="interview_action.php" style="margin:0;display:inline;"
                              onsubmit="return confirm('Cancel this interview?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-sm btn-outline">Cancel</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
