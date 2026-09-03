<?php
/**
 * Onboarding Dashboard — lists hired applicants in onboarding pipeline.
 * Tabs: Hired (not started) | In Progress | Completed
 */
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
require_once __DIR__ . '/../../includes/onboarding.php';

$activeTab = $_GET['tab'] ?? 'hired';
$validTabs = ['hired', 'progress', 'completed'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'hired';
}

$hiredApplicants = [];
if ($activeTab === 'hired') {
    $hiredApplicants = db()->query(
        "SELECT a.*, d.name AS department_name
         FROM applicants a
         LEFT JOIN departments d ON d.id = a.department_id
         WHERE a.status = 'hired'
           AND (a.onboarding_status IS NULL OR a.onboarding_status = '')
         ORDER BY a.applied_date DESC"
    )->fetchAll();
} elseif ($activeTab === 'progress') {
    $hiredApplicants = db()->query(
        "SELECT a.*, d.name AS department_name, op.current_stage, op.started_at
         FROM applicants a
         LEFT JOIN departments d ON d.id = a.department_id
         JOIN onboarding_progress op ON op.applicant_id = a.id
         WHERE a.status = 'hired'
           AND op.current_stage <> 'onboarding_completed'
         ORDER BY op.started_at DESC"
    )->fetchAll();
} else {
    $hiredApplicants = db()->query(
        "SELECT a.*, d.name AS department_name, op.onboarding_completed_at, op.account_created_at
         FROM applicants a
         LEFT JOIN departments d ON d.id = a.department_id
         JOIN onboarding_progress op ON op.applicant_id = a.id
         WHERE a.status = 'hired'
           AND op.current_stage = 'onboarding_completed'
         ORDER BY op.onboarding_completed_at DESC"
    )->fetchAll();
}

$pageTitle = 'Onboarding';
$currentModule = 'onboarding';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">New Hire Onboarding</h1>
        <p class="page-subtitle">Track hired applicants through the onboarding pipeline</p>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.05s">
    <div class="tab-bar">
        <?php
        $tabCounts = [
            'hired'     => db()->query("SELECT COUNT(*) FROM applicants WHERE status='hired' AND (onboarding_status IS NULL OR onboarding_status='')")->fetchColumn(),
            'progress'  => db()->query("SELECT COUNT(*) FROM applicants a JOIN onboarding_progress op ON op.applicant_id=a.id WHERE a.status='hired' AND op.current_stage<>'onboarding_completed'")->fetchColumn(),
            'completed' => db()->query("SELECT COUNT(*) FROM applicants a JOIN onboarding_progress op ON op.applicant_id=a.id WHERE a.status='hired' AND op.current_stage='onboarding_completed'")->fetchColumn(),
        ];
        foreach (['hired' => 'Hired (Not Started)', 'progress' => 'In Progress', 'completed' => 'Completed'] as $key => $label): ?>
        <a href="?tab=<?= $key ?>" class="tab-link <?= $activeTab === $key ? 'active' : '' ?>">
            <?= $label ?> <span class="tab-count"><?= $tabCounts[$key] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if (empty($hiredApplicants)): ?>
<section class="panel fade-in-up" style="animation-delay:.1s">
    <p style="text-align:center;padding:2rem 0;color:var(--text-muted);">
        <?php if ($activeTab === 'hired'): ?>
            No hired applicants pending onboarding.
        <?php elseif ($activeTab === 'progress'): ?>
            No applicants currently in onboarding.
        <?php else: ?>
            No completed onboardings yet.
        <?php endif; ?>
    </p>
</section>
<?php else: ?>
<section class="panel fade-in-up" style="animation-delay:.1s">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Position</th>
                    <th>Department</th>
                    <th>Hire Date</th>
                    <?php if ($activeTab === 'hired'): ?>
                        <th>Status</th>
                    <?php elseif ($activeTab === 'progress'): ?>
                        <th>Current Stage</th>
                        <th>Progress</th>
                    <?php else: ?>
                        <th>Completed</th>
                    <?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hiredApplicants as $a): ?>
                <tr>
                    <td>
                        <strong><?= e($a['first_name'] . ' ' . $a['last_name']) ?></strong>
                        <br><small style="color:var(--text-muted)"><?= e($a['applicant_no']) ?></small>
                    </td>
                    <td><?= e($a['position_applied']) ?></td>
                    <td><?= e($a['department_name'] ?? '—') ?></td>
                    <td><?= formatDate($a['applied_date']) ?></td>
                    <?php if ($activeTab === 'hired'): ?>
                        <td><?= statusBadge('hired') ?></td>
                    <?php elseif ($activeTab === 'progress'): ?>
                        <td><?= statusBadge(str_replace('_', ' ', $a['current_stage'])) ?></td>
                        <td>
                            <?php
                            $stages = onboardingStages();
                            $idx = array_search($a['current_stage'], $stages);
                            $pct = $idx !== false ? round(($idx + 1) / count($stages) * 100) : 0;
                            ?>
                            <div class="progress-bar" style="width:120px;display:inline-block;vertical-align:middle;">
                                <div class="progress-fill" style="width:<?= $pct ?>%"></div>
                            </div>
                            <?= $idx !== false ? ($idx + 1) . '/' . count($stages) : '0/' . count($stages) ?>
                        </td>
                    <?php else: ?>
                        <td><?= formatDate($a['onboarding_completed_at'] ?? null) ?></td>
                    <?php endif; ?>
                    <td>
                        <a href="applicant_view.php?id=<?= (int) $a['id'] ?>" class="btn btn-sm btn-primary">
                            <?= $activeTab === 'hired' ? 'Start Onboarding' : 'View Progress' ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
