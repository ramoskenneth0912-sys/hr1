<?php
require_once __DIR__ . '/../../../includes/auth.php';
requireHRorManager();

require_once __DIR__ . '/../../../includes/exam.php';

$flashOpts = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? 'assign');
    $applicantId = (int) ($_POST['applicant_id'] ?? 0);

    if ($applicantId <= 0) {
        flash('danger', 'Invalid applicant.');
        redirect(BASE_URL . '/modules/applicants/index.php');
    }

    if ($action === 'request') {
        // Represent the "Exam Assignment / Request" stage for an eligible
        // applicant without requiring an HR1-owned exam reference yet. The
        // future external HR3 provider fulfils it.
        $aStmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
        $aStmt->execute([$applicantId]);
        $requestApplicant = $aStmt->fetch();
        if (!$requestApplicant) {
            flash('danger', 'Applicant not found.');
            redirect(BASE_URL . '/modules/applicants/index.php');
        }
        [$ok, $message] = requestExamForApplicant($requestApplicant, (int) ($_SESSION['user_id'] ?? 0));
        if ($ok) {
            require_once __DIR__ . '/../../../includes/security_log.php';
            securityLog('exam_requested', "applicant_id={$applicantId}", $_SESSION['user_id'] ?? null);
            flash('success', $message);
        } else {
            flash('danger', $message);
        }
        redirect(BASE_URL . '/modules/recruitment/exams/assign.php?applicant_id=' . $applicantId);
    }

    // Assign a specific exam reference to a specific applicant.
    $examId = (int) ($_POST['exam_id'] ?? 0);
    if ($examId <= 0 || $applicantId <= 0) {
        flash('danger', 'Invalid examination or applicant.');
        redirect(BASE_URL . '/modules/applicants/index.php');
    }

    [$ok, $message] = assignExamToApplicant($examId, $applicantId, (int) ($_SESSION['user_id'] ?? 0));

    if ($ok) {
        require_once __DIR__ . '/../../../includes/security_log.php';
        securityLog('exam_assigned', "exam_id={$examId} applicant_id={$applicantId}", $_SESSION['user_id'] ?? null);

        // Notify the applicant's in-app account (server-side resolves their user).
        $a = db()->prepare('SELECT * FROM applicants WHERE id = ?');
        $a->execute([$applicantId]);
        $applicant = $a->fetch();
        if ($applicant) {
            $exam = findExam($examId);
            notifyUser(
                applicantUserId($applicant),
                'Examination assigned',
                'An examination for the ' . ($exam['job_title'] ?? '') . ' position has been assigned to you.',
                BASE_URL . '/modules/applicant/exams.php'
            );
        }

        flash('success', $message);
    } else {
        flash('danger', $message);
    }

    redirect(BASE_URL . '/modules/recruitment/exams/assign.php?applicant_id=' . $applicantId);
}

$applicantId = (int) ($_GET['applicant_id'] ?? 0);

$selectedApplicant = null;
$matchingExams = [];

if ($applicantId > 0) {
    $a = db()->prepare('SELECT * FROM applicants WHERE id = ?');
    $a->execute([$applicantId]);
    $selectedApplicant = $a->fetch();
    if ($selectedApplicant) {
        $matchingExams = activeExamsForJob((int) $selectedApplicant['job_posting_id']);
    }
}

$pageTitle = 'Assign Examination';
$currentModule = 'exams';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Assign Examination</h1>
        <p class="page-subtitle">Assign a job-specific examination to an applicant who passed initial screening</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/applicants/index.php" class="btn btn-outline">← Applicants</a>
</div>

<?php if (!$selectedApplicant): ?>
<section class="panel fade-in-up" style="animation-delay:.05s">
    <p class="empty">No applicant was specified. Select an applicant from Applicant Management, then choose <strong>Exam</strong> from their Options menu.</p>
</section>
<?php else: ?>

<!-- ===== Applicant (read-only) ===== -->
<section class="panel fade-in-up" style="animation-delay:.05s">
    <h2>1. Applicant</h2>
    <p>
        <strong><?= e($selectedApplicant['applicant_no']) ?> — <?= e($selectedApplicant['first_name'] . ' ' . $selectedApplicant['last_name']) ?></strong><br>
        <?= e($selectedApplicant['position_applied']) ?>
    </p>
</section>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>2. Assign Examination for <?= e($selectedApplicant['first_name'] . ' ' . $selectedApplicant['last_name']) ?></h2>

    <?php if (!applicantExamEligible($selectedApplicant)): ?>
    <div class="alert alert-danger">
        This applicant is <strong>not eligible</strong> for an examination. Eligibility requires that the applicant passed initial screening (status "Accepted" or "Passed Screening") and is linked to a Job/Position.
    </div>
    <?php else: ?>

    <?php
    // Does the applicant already have an assignment or a pending request?
    $existingAsn = db()->prepare('SELECT * FROM exam_assignments WHERE applicant_id = ? ORDER BY id DESC LIMIT 1');
    $existingAsn->execute([(int) $selectedApplicant['id']]);
    $alreadyAssigned = $existingAsn->fetch();
    ?>

    <?php if ($alreadyAssigned): ?>
    <div class="alert alert-warning">
        This applicant already has an examination <?= $alreadyAssigned['exam_id'] !== null ? 'assigned' : 'requested' ?>. An applicant is limited to one examination assignment/request per application.
    </div>
    <p>
        <strong>Status:</strong> <?= statusBadge($alreadyAssigned['status']) ?>
    </p>
    <?php else: ?>
    <form method="post" action="assign.php" style="margin:0 0 1.25rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request">
        <input type="hidden" name="applicant_id" value="<?= (int) $selectedApplicant['id'] ?>">
        <button type="submit" class="btn btn-primary">Request Examination</button>
    </form>
    <?php endif; ?>

    <?php if (empty($matchingExams)): ?>
    <div class="alert alert-warning" style="margin-bottom:0;">
        No active examination <strong>reference</strong> is defined for the applied position
        "<?= e($selectedApplicant['position_applied']) ?>".
    </div>
    <?php else: ?>
    <h3 style="margin:0 0 .5rem;">Or assign a specific examination reference</h3>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Examination</th>
                <th>Job / Position</th>
                <th>Provider</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($matchingExams as $ex): ?>
            <tr>
                <td><?= e($ex['title']) ?></td>
                <td><?= e($ex['job_code'] ?? '—') ?> — <?= e($ex['job_title'] ?? '—') ?></td>
                <td><?= e($ex['source_provider']) ?></td>
                <td><?= statusBadge($ex['status']) ?></td>
                <td>
                    <?php
                    $alreadyDb = db()->prepare('SELECT id, status FROM exam_assignments WHERE exam_id = ? AND applicant_id = ? LIMIT 1');
                    $alreadyDb->execute([(int) $ex['id'], (int) $selectedApplicant['id']]);
                    $already = $alreadyDb->fetch();
                    ?>
                    <?php if ($already): ?>
                    <span class="badge badge-secondary">Already assigned (<?= statusBadge($already['status']) ?>)</span>
                    <?php elseif ($alreadyAssigned): ?>
                    <span class="badge badge-secondary">One assignment per applicant</span>
                    <?php else: ?>
                    <form method="post" action="assign.php" style="margin:0;display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="exam_id" value="<?= (int) $ex['id'] ?>">
                        <input type="hidden" name="applicant_id" value="<?= (int) $selectedApplicant['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Assign Examination</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
