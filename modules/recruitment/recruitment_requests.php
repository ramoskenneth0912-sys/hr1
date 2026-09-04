<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$pageTitle = 'Recruitment Requests';
$currentModule = 'recruitment';
require_once __DIR__ . '/../../includes/header.php';

$requests = db()->query(
    'SELECT rr.*, d.name AS department_name
     FROM recruitment_requests rr
     LEFT JOIN departments d ON rr.department_id = d.id
     ORDER BY rr.created_at DESC'
)->fetchAll();
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Recruitment Requests</h1>
        <p class="page-subtitle">Incoming recruitment requests from succession planning</p>
    </div>
    <div class="btn-group">
        <a href="job_create.php" class="btn btn-primary">+ Job Posting</a>
        <a href="job_create.php" class="btn btn-outline">← Back</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2 id="requests-list">Incoming Requests</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Request Code</th>
                <th>Position</th>
                <th>Department</th>
                <th>Vacancies</th>
                <th>Source</th>
                <th>Request Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($requests)): ?>
            <tr><td colspan="8" class="empty">No recruitment requests yet.</td></tr>
            <?php else: foreach ($requests as $row): ?>
            <tr>
                <td><?= e($row['request_code']) ?></td>
                <td><?= e($row['position_title']) ?></td>
                <td><?= e($row['department_name'] ?? '—') ?></td>
                <td><?= (int) $row['number_of_positions'] ?></td>
                <td><?= e($row['source'] ?? '—') ?></td>
                <td><?= formatDate($row['request_date']) ?></td>
                <td><?= statusBadge($row['status']) ?></td>
                <td>
                    <?php if ($row['status'] === 'pending'): ?>
                    <a href="job_create.php?from_request=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline">Create Job Posting</a>
                    <?php else: ?>
                    <span style="color:var(--muted);font-size:.8rem;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
