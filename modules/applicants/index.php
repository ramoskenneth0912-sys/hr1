<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
$pageTitle = 'Applicant Management';
$currentModule = 'applicants';
require_once __DIR__ . '/../../includes/header.php';

require_once __DIR__ . '/../../includes/ai_screening.php';

$applicants = getApplicants();

// Load screening results for all applicants
$screeningMap = [];
$ids = array_column($applicants, 'id');
if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sStmt = db()->prepare(
        "SELECT s.applicant_id, s.overall_score, s.recommendation
         FROM ai_screening s
         INNER JOIN (
             SELECT applicant_id, MAX(id) AS max_id
             FROM ai_screening
             WHERE applicant_id IN ($placeholders)
             GROUP BY applicant_id
         ) latest ON s.id = latest.max_id"
    );
    $sStmt->execute($ids);
    foreach ($sStmt->fetchAll() as $sRow) {
        $screeningMap[$sRow['applicant_id']] = $sRow;
    }
}
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Applicant Management</h1>
        <p class="page-subtitle">Module 1 — Track job applicants through the hiring pipeline</p>
    </div>
    <div class="btn-group">
        <a href="create.php" class="btn btn-primary">+ New Applicant</a>
        <a href="interview_history.php" class="btn btn-outline">Interview History</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Applicant No.</th>
                <th>Name</th>
                <th>Position</th>
                <th>AI Match</th>
                <th>Recommendation</th>
                <th>Status</th>
                <th>Applied</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($applicants)): ?>
            <tr><td colspan="8" class="empty">No applicants yet. <a href="create.php">Add the first applicant</a>.</td></tr>
            <?php else: foreach ($applicants as $row):
                $sc = $screeningMap[$row['id']] ?? null;
            ?>
            <tr>
                <td><?= e($row['applicant_no']) ?></td>
                <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                <td><?= e($row['position_applied']) ?></td>
                <td>
                    <?php if ($sc): ?>
                    <span class="ai-score-badge" style="color:<?= $sc['overall_score'] >= 80 ? 'var(--success)' : ($sc['overall_score'] >= 60 ? 'var(--info)' : ($sc['overall_score'] >= 40 ? 'var(--warning)' : 'var(--danger)')) ?>;">
                        <?= (int) $sc['overall_score'] ?>%
                    </span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($sc): ?>
                    <span class="badge <?= $sc['recommendation'] === 'Strong Match' ? 'badge-success' : ($sc['recommendation'] === 'Good Match' ? 'badge-info' : ($sc['recommendation'] === 'Moderate Match' ? 'badge-warning' : 'badge-danger')) ?>"><?= e($sc['recommendation']) ?></span>
                    <?php else: ?>
                    <span class="badge badge-secondary">Unscreened</span>
                    <?php endif; ?>
                </td>
                <td><?= statusBadge($row['status']) ?></td>
                <td><?= formatDate($row['applied_date']) ?></td>
                <td class="actions">
                    <div class="btn-group" style="flex-wrap:nowrap;">
                        <?php if ($sc): ?>
                        <a href="screening_view.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-primary">AI Screening</a>
                        <?php else: ?>
                        <form method="post" action="screening.php" style="margin:0;display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="applicant_id" value="<?= (int) $row['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-primary">Run Screening</button>
                        </form>
                        <?php endif; ?>
                        <?php if (in_array($row['status'], ['accepted', 'passed_screening'], true)): ?>
                        <a href="schedule_interview.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-primary">Schedule Interview</a>
                        <?php endif; ?>
                        <a href="view.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline">View</a>
                        <a href="edit.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
