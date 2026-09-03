<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
$pageTitle = 'Applicant Management';
$currentModule = 'applicants';
require_once __DIR__ . '/../../includes/header.php';

require_once __DIR__ . '/../../includes/ai_screening.php';
require_once __DIR__ . '/../../includes/exam.php';

// =====================================================================
// Recruitment workflow tabs (mapped to the EXISTING HR1 statuses). No
// new/duplicate statuses are introduced. `offered` represents "Selected".
//   - new       : new               (newly submitted, screening not started)
//   - screening : screening / shortlisted (pending initial screening)
//   - passed    : accepted / passed_screening (screening passed → exam)
//   - exam      : screening passed AND an active examination is assigned
//   - final     : passed exam → ready for final interview
//   - rejected  : rejected
// Interview / Selected / Hired are intentionally NOT shown as filter tabs
// here, but their statuses (interview / offered / hired) remain fully
// intact in the database and recruitment workflow (see Options actions).
// =====================================================================
$tabs = [
    'all'          => ['label' => 'All'],
    'new'          => ['label' => 'New', 'statuses' => ['new']],
    'screening'    => ['label' => 'Screening',   'statuses' => ['screening', 'shortlisted']],
    'passed'       => ['label' => 'Screening Passed', 'statuses' => ['accepted', 'passed_screening']],
    'exam'         => ['label' => 'Exam'],
    'exam_results' => ['label' => 'Exam Results'],
    'final'        => ['label' => 'Ready for Final Interview'],
    'rejected'     => ['label' => 'Rejected',    'statuses' => ['rejected']],
];

$tab = trim((string) ($_GET['tab'] ?? 'all'));
if (!isset($tabs[$tab])) {
    $tab = 'all';
}

// Search + filters (validated server-side).
$q         = trim((string) ($_GET['q'] ?? ''));
$position  = trim((string) ($_GET['position'] ?? ''));

// Build the base query with server-side filtering.
$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(a.first_name LIKE ? OR a.last_name LIKE ? OR CONCAT(a.first_name," ",a.last_name) LIKE ?
                 OR a.applicant_no LIKE ? OR a.email LIKE ? OR a.position_applied LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if ($position !== '') {
    $where[] = 'a.position_applied = ?';
    $params[] = $position;
}

$sql = 'SELECT a.*, d.name AS department_name, j.title AS job_title
        FROM applicants a
        LEFT JOIN departments d ON a.department_id = d.id
        LEFT JOIN job_postings j ON a.job_posting_id = j.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY a.created_at DESC, a.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$allApplicants = $stmt->fetchAll();

// Load the latest AI screening result per applicant.
$screeningMap = [];
$ids = array_column($allApplicants, 'id');
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

// Load active examination assignments per applicant (drives Exam tab,
// Exam Result action and final-interview eligibility).
$examMap = [];
if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $eStmt = db()->prepare(
        "SELECT ea.applicant_id, ea.id AS assignment_id, ea.status AS assignment_status,
                e.title AS exam_title, e.job_posting_id AS exam_job_id,
                (SELECT er.passed FROM exam_results er
                   JOIN exam_attempts at ON at.id = er.attempt_id
                  WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1) AS result_passed,
                (SELECT er.percentage FROM exam_results er
                   JOIN exam_attempts at ON at.id = er.attempt_id
                  WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1) AS result_percentage,
                (SELECT er.result_status FROM exam_results er
                   JOIN exam_attempts at ON at.id = er.attempt_id
                  WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1) AS result_status,
                (SELECT er.scored_at FROM exam_results er
                   JOIN exam_attempts at ON at.id = er.attempt_id
                  WHERE at.assignment_id = ea.id ORDER BY er.id DESC LIMIT 1) AS result_date
         FROM exam_assignments ea
         LEFT JOIN exams e ON e.id = ea.exam_id AND e.status = 'active'
         WHERE ea.applicant_id IN ($placeholders)
         ORDER BY ea.id DESC"
    );
    $eStmt->execute($ids);
    foreach ($eStmt->fetchAll() as $eRow) {
        $aid = (int) $eRow['applicant_id'];
        if (!isset($examMap[$aid])) {
            $examMap[$aid] = $eRow;
        }
    }
}

// =====================================================================
// Per-applicant stage resolution (used to build the Options menu and to
// filter the Exam tab). Mirrors the server-side eligibility rules in
// includes/exam.php — display only; authorization stays server-side.
// =====================================================================
$resolved = [];
foreach ($allApplicants as $row) {
    $status = (string) ($row['status'] ?? '');
    $asn = $examMap[(int) $row['id']] ?? null;

    $screeningPassed       = in_array($status, ['accepted', 'passed_screening'], true);
    $hasExamAssignment     = $asn !== null;
    $resultPassed          = $hasExamAssignment && (int) $asn['result_passed'] === 1;
    // Final-interview eligibility: passed screening, has an active exam on the
    // applied job with a passing result (same rule as applicantFinalInterviewEligible).
    $finalInterviewEligible = $screeningPassed
        && $hasExamAssignment
        && $resultPassed
        && (int) ($asn['exam_job_id'] ?? 0) === (int) ($row['job_posting_id'] ?? 0);

    $row['_has_exam_assignment']     = $hasExamAssignment;
    $row['_result_passed']           = $resultPassed;
    $row['_final_interview_eligible'] = $finalInterviewEligible;
    $row['_exam_assignment_id']      = $asn ? (int) $asn['assignment_id'] : null;
    // Additional exam / result fields (drives the Exam Results view).
    $row['_exam_title']              = $asn ? (string) ($asn['exam_title'] ?? '') : '';
    $row['_exam_assignment_status']  = $asn ? (string) ($asn['assignment_status'] ?? '') : '';
    $row['_exam_percentage']         = $asn ? $asn['result_percentage'] : null;
    $row['_exam_result_status']      = $asn ? (string) ($asn['result_status'] ?? '') : '';
    $row['_exam_result_date']        = $asn ? ($asn['result_date'] ?? null) : null;
    // "Has a returned result" (= the applicant took/completed the exam).
    $row['_has_exam_result']         = $hasExamAssignment && $asn && ($asn['result_status'] ?? '') !== '';

    $stage = 'screening';
    if ($status === 'rejected')          $stage = 'rejected';
    elseif ($status === 'hired')         $stage = 'hired';
    elseif ($status === 'offered')       $stage = 'selected';
    elseif ($status === 'interview')     $stage = 'interview';
    elseif ($screeningPassed)            $stage = 'passed';
    $row['_stage'] = $stage;

    $resolved[] = $row;
}

// Tab counts (computed on the search/filtered base set so they reflect context).
$tabCounts = ['all' => count($resolved)];
foreach (array_keys($tabs) as $t) {
    if ($t === 'all') continue;
    $tabCounts[$t] = 0;
}
foreach ($resolved as $row) {
    foreach ($tabs as $t => $def) {
        if ($t === 'all') continue;
        if ($t === 'exam') {
            if ($row['_stage'] === 'passed' && $row['_has_exam_assignment']) $tabCounts['exam']++;
            continue;
        }
        if ($t === 'exam_results') {
            if ($row['_has_exam_result']) $tabCounts['exam_results']++;
            continue;
        }
        if ($t === 'final') {
            if ($row['_final_interview_eligible']) $tabCounts['final']++;
            continue;
        }
        if (in_array($row['status'], $def['statuses'] ?? [], true)) $tabCounts[$t]++;
    }
}

// Apply the active tab filter.
$applicants = $resolved;
if ($tab !== 'all') {
    if ($tab === 'exam') {
        $applicants = array_filter($applicants, fn ($r) => $r['_stage'] === 'passed' && $r['_has_exam_assignment']);
    } elseif ($tab === 'exam_results') {
        $applicants = array_filter($applicants, fn ($r) => $r['_has_exam_result']);
    } elseif ($tab === 'final') {
        $applicants = array_filter($applicants, fn ($r) => $r['_final_interview_eligible']);
    } else {
        $allowed = $tabs[$tab]['statuses'];
        $applicants = array_filter($applicants, fn ($r) => in_array($r['status'], $allowed, true));
    }
    $applicants = array_values($applicants);
}

// Distinct positions for the filter dropdown.
$distinctPositions = db()->query(
    "SELECT DISTINCT position_applied FROM applicants WHERE position_applied <> '' ORDER BY position_applied"
)->fetchAll(PDO::FETCH_COLUMN);

// Build the search/filter query string (preserve across tabs).
$qs = [];
if ($q !== '')        $qs['q'] = $q;
if ($position !== '') $qs['position'] = $position;
$filterQuery = http_build_query($qs);
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Applicant Management</h1>
        <p class="page-subtitle"></p>
    </div>
    <div class="btn-group">
        <a href="<?= BASE_URL ?>/modules/applicants/exam_history.php" class="btn btn-outline">Exam History</a>
        <a href="interview_history.php" class="btn btn-outline">Interview History</a>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.05s">
    <!-- Status filter tabs -->
    <div class="apps-tabs" role="tablist" aria-label="Filter applicants by stage">
        <?php foreach ($tabs as $key => $def): ?>
        <?php
            $href = 'index.php?tab=' . urlencode($key);
            if ($filterQuery !== '') $href .= '&' . $filterQuery;
            $active = $tab === $key ? ' active' : '';
        ?>
        <a href="<?= e($href) ?>" class="apps-tab<?= $active ?>" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>">
            <?= e($def['label']) ?>
            <?php if (isset($tabCounts[$key]) && $tabCounts[$key] > 0): ?>
            <span class="apps-tab-count"><?= (int) $tabCounts[$key] ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Search + filters -->
    <form method="get" action="index.php" class="apps-filters">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="apps-search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input
                type="search"
                name="q"
                value="<?= e($q) ?>"
                placeholder="Search by name, applicant no., position, or email..."
                aria-label="Search applicants">
        </div>
        <select name="position" class="apps-select" aria-label="Filter by position">
            <option value="">All Positions</option>
            <?php foreach ($distinctPositions as $p): ?>
            <option value="<?= e($p) ?>" <?= $position === $p ? 'selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($q !== '' || $position !== ''): ?>
        <a href="index.php?tab=<?= e($tab) ?>" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
    </form>
</section>

<section class="panel fade-in-up apps-panel" style="animation-delay:.1s">
    <div class="table-wrap">
    <table class="data-table apps-table">
        <thead>
            <tr>
                <th>Applicant No.</th>
                <th>Applicant Name</th>
                <th>Position</th>
                <th>AI Match</th>
                <th>Recommendation</th>
                <th>Status</th>
                <th>Applied</th>
                <th>Options</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($applicants)): ?>
            <tr><td colspan="8" class="empty">
                <?= $tab !== 'all' || $q !== '' || $position !== ''
                    ? 'No applicants match the current filter.'
                    : 'No applicants yet. <a href="create.php">Add the first applicant</a>.' ?>
            </td></tr>
            <?php else: foreach ($applicants as $row):
                $sc = $screeningMap[$row['id']] ?? null;
                $stage = $row['_stage'];
            ?>
            <tr>
                <td><?= e($row['applicant_no']) ?></td>
                <td>
                    <a href="view.php?id=<?= (int) $row['id'] ?>" class="applicant-name"><?= e($row['first_name'] . ' ' . $row['last_name']) ?></a>
                    <div class="applicant-sub"><?= e($row['email']) ?></div>
                </td>
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
                <td>
                    <?= statusBadge($row['status']) ?>
                    <?php if ($row['_final_interview_eligible']): ?>
                    <div class="apps-ready-badge">READY FOR FINAL INTERVIEW</div>
                    <?php endif; ?>
                </td>
                <td><?= formatDate($row['applied_date']) ?></td>
                <td class="actions">
                    <div class="apps-menu">
                        <button type="button" class="btn btn-sm btn-outline apps-menu-toggle" aria-haspopup="true" aria-expanded="false">
                            Options <span class="apps-caret">▾</span>
                        </button>
                        <div class="apps-menu-dropdown" role="menu">
                            <?php
                            $has = function (string $label, string $url) {
                                echo '<a class="apps-menu-item" href="' . e($url) . '" role="menuitem">' . e($label) . '</a>';
                            };
                            $post = function (string $label, string $actionUrl, ?string $status = null) use ($row) {
                                $html = '<form method="post" action="' . e($actionUrl) . '" class="apps-menu-form">'
                                   . csrf_field()
                                   . '<input type="hidden" name="applicant_id" value="' . (int) $row['id'] . '">';
                                if ($status !== null) {
                                    $html .= '<input type="hidden" name="status" value="' . e($status) . '">';
                                }
                                $html .= '<button type="submit" class="apps-menu-item apps-menu-btn">' . e($label) . '</button></form>';
                                echo $html;
                            };
                            ?>
                            <?php
                            // Core actions shown for every applicant (View, Edit).
                            // "AI Screening" is shown ONLY until it has actually
                            // been completed. Completion is determined from the
                            // EXISTING ai_screening record for this applicant
                            // ($sc, loaded from DB per applicant above) — never
                            // from browser/session state. Once a screening row
                            // exists the option is removed entirely so it cannot
                            // be re-run (reusing HR1's own screening completion
                            // result; no new status system is introduced).
                            $has('View', 'view.php?id=' . (int) $row['id']);
                            $has('Edit', 'edit.php?id=' . (int) $row['id']);

                            if (!$sc):
                                $post('AI Screening', 'screening.php');
                            endif;
                            ?>

                            <?php
                            // Exam eligibility reuses HR1's existing business rule
                            // (includes/exam.php): the applicant must have PASSED /
                            // ACCEPTED Initial Screening (status in
                            // ['accepted','passed_screening']) AND be linked to an
                            // applied Job/Position (the exam is position-scoped).
                            // This is a UI convenience only — the server-side
                            // assign/record routes enforce the same eligibility
                            // independently, so an ineligible applicant cannot
                            // assign/take an exam by editing the URL.
                            if (applicantExamEligible($row)): ?>
                                <?php if ($row['_has_exam_result']): ?>
                                    <?php $has('Exam Result', BASE_URL . '/modules/recruitment/exams/record_result.php?assignment_id=' . (int) $row['_exam_assignment_id']); ?>
                                <?php elseif ($row['_exam_assignment_id']): ?>
                                    <?php $has('Requested', BASE_URL . '/modules/recruitment/exams/assign.php?applicant_id=' . (int) $row['id']); ?>
                                <?php else: ?>
                                    <?php $has('Assign Exam', BASE_URL . '/modules/recruitment/exams/assign.php?applicant_id=' . (int) $row['id']); ?>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div class="apps-menu-divider"></div>

                            <?php
                            // Stage-specific actions (kept so no existing action or
                            // route is lost). These are in addition to the four core
                            // actions above and never duplicate them.
                            if ($stage === 'passed' && $row['_final_interview_eligible']):
                                $has('Final Interview', BASE_URL . '/modules/recruitment/interview_create.php?applicant_id=' . (int) $row['id']);
                            elseif ($stage === 'interview'):
                                $has('Interview History', 'interview_history.php');
                                $post('Mark Selected', 'status.php', 'offered');
                            elseif ($stage === 'selected'):
                                $post('Mark Hired', 'status.php', 'hired');
                            endif;
                            ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php if ($tab === 'exam_results'): ?>
<section class="panel fade-in-up apps-panel" style="animation-delay:.15s">
    <div class="panel-header">
        <div>
            <h2>Exam Results</h2>
            <p class="panel-desc">Applicants who have taken/completed an examination and their Final Interview readiness.</p>
        </div>
    </div>
    <div class="table-wrap">
    <table class="data-table apps-table">
        <thead>
            <tr>
                <th>Applicant No.</th>
                <th>Applicant Name</th>
                <th>Position</th>
                <th>Exam</th>
                <th>Exam Status</th>
                <th>Score</th>
                <th>Result</th>
                <th>Exam Date</th>
                <th>Final Interview</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($applicants)): ?>
            <tr><td colspan="10" class="empty">No applicants have completed an exam yet.</td></tr>
            <?php else: foreach ($applicants as $row): ?>
            <tr>
                <td><?= e($row['applicant_no']) ?></td>
                <td>
                    <a href="view.php?id=<?= (int) $row['id'] ?>" class="applicant-name"><?= e($row['first_name'] . ' ' . $row['last_name']) ?></a>
                    <div class="applicant-sub"><?= e($row['email']) ?></div>
                </td>
                <td><?= e($row['position_applied']) ?></td>
                <td><?= e($row['_exam_title'] ?: '—') ?></td>
                <td><?= statusBadge($row['_exam_assignment_status']) ?></td>
                <td><?= $row['_exam_percentage'] !== null ? e((string) $row['_exam_percentage']) . '%' : '—' ?></td>
                <td>
                    <?php if ($row['_has_exam_result']): ?>
                    <span class="badge <?= $row['_result_passed'] ? 'badge-success' : 'badge-danger' ?>">
                        <?= $row['_result_passed'] ? 'PASSED' : 'FAILED' ?>
                    </span>
                    <?php else: ?>
                    <span class="badge badge-secondary">No result</span>
                    <?php endif; ?>
                </td>
                <td><?= $row['_exam_result_date'] ? formatDate($row['_exam_result_date']) : '—' ?></td>
                <td>
                    <?php if ($row['_final_interview_eligible']): ?>
                    <span class="badge badge-success">READY</span>
                    <?php else: ?>
                    <span class="badge badge-secondary">Not Eligible</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <?php if ($row['_exam_assignment_id']): ?>
                    <a class="btn btn-sm btn-outline" href="<?= BASE_URL ?>/modules/recruitment/exams/record_result.php?assignment_id=<?= (int) $row['_exam_assignment_id'] ?>">Exam Result</a>
                    <?php endif; ?>
                    <?php if ($row['_final_interview_eligible']): ?>
                    <a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>/modules/recruitment/interview_create.php?applicant_id=<?= (int) $row['id'] ?>">Final Interview</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<style>
.apps-tabs { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1.25rem; }
.apps-tab {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem .9rem; border: 1px solid var(--border); border-radius: 999px;
    font-size: .8rem; font-weight: 600; color: var(--text); background: var(--surface);
    transition: all .15s;
}
.apps-tab:hover { border-color: var(--purple-light); color: var(--purple); text-decoration: none; }
.apps-tab.active { background: linear-gradient(135deg, var(--purple-dark), var(--purple)); color: #fff; border-color: transparent; box-shadow: 0 4px 14px rgba(123,44,191,.3); }
.apps-tab-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 20px; height: 20px; padding: 0 .35rem; border-radius: 999px;
    background: var(--purple-bg); color: var(--purple); font-size: .68rem; font-weight: 700;
}
.apps-tab.active .apps-tab-count { background: rgba(255,255,255,.25); color: #fff; }

.apps-filters { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.apps-search {
    position: relative; display: flex; align-items: center; flex: 1 1 260px; min-width: 220px;
    color: var(--muted); background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm);
}
.apps-search svg { position: absolute; left: .7rem; pointer-events: none; }
.apps-search input {
    width: 100%; padding: .6rem .875rem .6rem 2.3rem; border: 0; background: transparent;
    font-family: inherit; font-size: .875rem; color: var(--text);
}
.apps-search input:focus { outline: none; }
.apps-search:focus-within { border-color: var(--purple-light); box-shadow: 0 0 0 3px var(--purple-bg); }
.apps-select {
    padding: .6rem .75rem; border: 1px solid var(--border); border-radius: var(--radius-sm);
    font-size: .85rem; font-family: inherit; color: var(--text); background: var(--surface);
}

.applicant-name { font-weight: 600; color: var(--text-dark); }
.applicant-name:hover { color: var(--purple); }
.applicant-sub { font-size: .72rem; color: var(--muted); }

/* Subtle "ready for final interview" indicator shown alongside a PASSED
   exam applicant's status. Small and unobtrusive (see requirements). */
.apps-ready-badge {
    display: inline-block; margin-top: .35rem;
    padding: .18rem .5rem; border-radius: 999px;
    background: var(--success);
    color: #fff; font-size: .62rem; font-weight: 700; letter-spacing: .03em;
}

/* Applicant table & panel must appear immediately — no page-wide fade/slide
   entrance animation. (Also guarantees the fixed Options dropdown is never
   displaced/clipped by an animated transformed ancestor.) */
.fade-in-up.apps-panel { animation: none !important; opacity: 1 !important; transform: none !important; }
.apps-table tbody tr { animation: none !important; opacity: 1 !important; transform: none !important; }

/* Options dropdown — fixed positioning so the menu escapes the .table-wrap
   overflow container and can never be clipped by it or the viewport. */
.apps-menu { position: relative; display: inline-block; }
.apps-menu-toggle { white-space: nowrap; }
.apps-caret { margin-left: .15rem; font-size: .65rem; }
.apps-menu-dropdown {
    display: none;
    position: fixed;            /* out of the scrolling/clipping table-wrap */
    z-index: 1000;
    min-width: 180px;
    max-width: min(280px, calc(100vw - 16px));
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-lg);
    padding: .4rem;
    overflow-y: auto;           /* safe internal scroll if viewport is very short */
    overflow-x: hidden;
}
.apps-menu.open .apps-menu-dropdown { display: block; }
.apps-menu-item {
    display: block; width: 100%; text-align: left; padding: .55rem .75rem; border-radius: 8px;
    font-size: .82rem; font-weight: 500; color: var(--text);
}
.apps-menu-item:hover { background: var(--purple-bg); color: var(--purple); text-decoration: none; }
.apps-menu-form { margin: 0; }
.apps-menu-btn { border: 0; background: none; cursor: pointer; font-family: inherit; }
.apps-menu-divider { height: 1px; background: var(--border); margin: .35rem .25rem; }

/* Keep columns readable on smaller screens: the table scrolls horizontally
   instead of squeezing the columns into unreadable widths. */
.apps-table { min-width: 780px; }

@media (max-width: 640px) {
    .apps-tabs { gap: .3rem; }
    .apps-filters { flex-direction: column; align-items: stretch; }
    .apps-search { flex: 1 1 auto; }
    .apps-select { width: 100%; }
}
</style>

<script>
document.querySelectorAll('.apps-menu').forEach(function (menu) {
    var toggle = menu.querySelector('.apps-menu-toggle');
    var dropdown = menu.querySelector('.apps-menu-dropdown');
    var gap = 6;
    var margin = 8;

    function close() {
        menu.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    // Position the fixed dropdown so it stays fully inside the viewport.
    function position() {
        // Force a reflow so width/height reflect the now-visible menu (guards
        // against a stale 0 measurement right after display:block is applied).
        void dropdown.offsetHeight;

        var r = toggle.getBoundingClientRect();
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var dw = dropdown.offsetWidth;
        var dh = dropdown.offsetHeight;

        // Horizontal: align the menu's right edge with the button's right edge,
        // then clamp so it never leaves the viewport (pushes left near the right
        // edge, right near the left edge).
        var left = r.right - dw;
        left = Math.max(margin, Math.min(left, vw - dw - margin));
        if (vw - dw - margin < margin) left = margin;

        // Vertical: open downward by default; flip upward when not enough room.
        var top = r.bottom + gap;
        var opensDown = (top + dh) <= vh;
        if (!opensDown) {
            top = r.top - gap - dh;                 // flip up
            if (top < margin) {                      // still too tall: clamp + scroll
                dropdown.style.maxHeight = Math.max(120, r.top - gap - margin) + 'px';
                top = margin;
            } else {
                dropdown.style.maxHeight = '';
            }
        } else {
            dropdown.style.maxHeight = '';
        }

        dropdown.style.left = left + 'px';
        dropdown.style.top = top + 'px';
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        if (menu.classList.contains('open')) { close(); return; }
        document.querySelectorAll('.apps-menu.open').forEach(function (m) {
            m.classList.remove('open');
            m.querySelector('.apps-menu-toggle').setAttribute('aria-expanded', 'false');
        });
        menu.classList.add('open');
        toggle.setAttribute('aria-expanded', 'true');
        position();
    });

    // Keep the menu aligned with its button when the viewport resizes or the
    // table scrolls (capture handles the scrolling .table-wrap container).
    window.addEventListener('resize', function () { if (menu.classList.contains('open')) position(); });
    window.addEventListener('scroll', function () { if (menu.classList.contains('open')) position(); }, true);
});
document.addEventListener('click', function (e) {
    var target = e.target;
    document.querySelectorAll('.apps-menu.open').forEach(function (menu) {
        if (!menu.contains(target)) {
            menu.classList.remove('open');
            menu.querySelector('.apps-menu-toggle').setAttribute('aria-expanded', 'false');
        }
    });
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.apps-menu.open').forEach(function (menu) {
            menu.classList.remove('open');
            menu.querySelector('.apps-menu-toggle').setAttribute('aria-expanded', 'false');
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
