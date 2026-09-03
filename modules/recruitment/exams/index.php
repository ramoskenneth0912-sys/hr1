<?php
require_once __DIR__ . '/../../../includes/auth.php';
requireHRorManager();

require_once __DIR__ . '/../../../includes/exam.php';

$exams = allExams();

$pageTitle = 'Examinations';
$currentModule = 'exams';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Examinations</h1>
        <p class="page-subtitle">Recruitment — assign job-specific examinations to eligible applicants</p>
    </div>
    <div class="btn-group">
        <a href="create.php" class="btn btn-primary">+ New Examination</a>
        <a href="<?= BASE_URL ?>/modules/recruitment/index.php" class="btn btn-outline">Recruitment</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Examination References</h2>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Job / Position</th>
                <th>Provider</th>
                <th>Status</th>
                <th>Assigned</th>
                <th>Completed</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($exams)): ?>
            <tr><td colspan="7" class="empty">No examinations defined yet. Examinations are HR1-side references to a future external (HR3) exam provider.</td></tr>
            <?php else: foreach ($exams as $row): ?>
            <tr>
                <td><?= e($row['title']) ?></td>
                <td><?= e($row['job_code'] ?? '—') ?> — <?= e($row['job_title'] ?? 'No job linked') ?></td>
                <td><?= e($row['source_provider']) ?></td>
                <td><?= statusBadge($row['status']) ?></td>
                <td><?= (int) $row['assignments_count'] ?></td>
                <td><?= (int) $row['completed_count'] ?></td>
                <td class="actions">
                    <div class="btn-group" style="flex-wrap:nowrap;">
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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
