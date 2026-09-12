<?php
/**
 * HR / Admin — Performance Management
 * Phase 8B: Core HCM Performance module for HR and Manager users.
 *
 * This page is the management-side counterpart to the employee's
 * modules/employee/performance.php (My Performance) page.
 *
 * Security:
 *  - requireHRorManager() gates the page to HR/Manager roles only.
 *  - All write operations go through the existing PerformanceController API
 *    endpoints which enforce RBAC, manager scope, lifecycle rules, and
 *    prepared statements server-side.
 *  - CSRF tokens are embedded in every form/AJAX call.
 *  - HTML output is escaped via e().
 */
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
requireNotApplicant();

$currentUser   = getCurrentUser();
$isManager     = isManager();
$isHR          = isHR();
$managerEmpId  = (int) ($currentUser['employee_id'] ?? 0);
$pageTitle     = 'Performance Management';
$currentModule = 'performance';

// ---------------------------------------------------------------------------
// Server-side data: review periods and reviewer-capable users (for dropdowns)
// These are read-only queries — all writes go through the API.
// ---------------------------------------------------------------------------

// Fetch review periods (all roles can read; managers cannot create via API).
$periods = [];
try {
    $periodRows = db()->query('SELECT id, name, period_type, start_date, end_date, status FROM review_periods ORDER BY start_date DESC, id DESC')->fetchAll();
    $periods = $periodRows;
} catch (Throwable $e) {
    error_log('Performance: period fetch failed: ' . $e->getMessage());
}

// Fetch employees in scope (HR = all; Manager = own direct reports).
$employees = [];
try {
    $empSql    = 'SELECT id, employee_no, first_name, last_name FROM employees WHERE status != \'terminated\'';
    $empParams = [];
    if ($isManager && $managerEmpId > 0) {
        $empSql    .= ' AND manager_id = ?';
        $empParams[] = $managerEmpId;
    }
    $empStmt = db()->prepare($empSql . ' ORDER BY last_name, first_name');
    $empStmt->execute($empParams);
    $employees = $empStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Performance: employee fetch failed: ' . $e->getMessage());
}
$employeeIds = array_map(static fn(array $r): int => (int) $r['id'], $employees);

// Fetch active users for reviewer dropdown (only users who could be reviewers).
$reviewerUsers = [];
try {
    $reviewerUsers = db()->query(
        'SELECT u.id, u.username, e.first_name, e.last_name
         FROM users u
         LEFT JOIN employees e ON e.id = u.employee_id
         WHERE u.is_active = 1 AND u.role IN (\'hr\', \'manager\')
         ORDER BY u.username'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Performance: reviewer fetch failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Filters from GET (for the review list table).
// ---------------------------------------------------------------------------
$filterPeriodId  = (int) ($_GET['period_id']   ?? 0);
$filterStatus    = trim((string) ($_GET['status']       ?? ''));
$filterEmpId     = (int) ($_GET['employee_id'] ?? 0);

$validStatuses = ['drafted', 'assigned', 'self_assessment', 'manager_review', 'finalized', 'acknowledged'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}
if ($filterEmpId > 0 && !in_array($filterEmpId, $employeeIds, true)) {
    // Managers must not filter on out-of-scope employees.
    $filterEmpId = 0;
}

// ---------------------------------------------------------------------------
// Review list query (server-rendered table).
// ---------------------------------------------------------------------------
$reviews = [];
try {
    $where  = [];
    $params = [];

    if ($isManager && $managerEmpId > 0) {
        $where[]            = '(e.manager_id = ? OR p.reviewer_user_id = ?)';
        $params[]           = $managerEmpId;
        $params[]           = (int) ($currentUser['id'] ?? 0);
    }
    if ($filterPeriodId > 0) {
        $where[]  = 'p.period_id = ?';
        $params[] = $filterPeriodId;
    }
    if ($filterStatus !== '') {
        $where[]  = 'p.status = ?';
        $params[] = $filterStatus;
    }
    if ($filterEmpId > 0) {
        $where[]  = 'p.employee_id = ?';
        $params[] = $filterEmpId;
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $reviewSql =
        'SELECT p.id, p.employee_id, p.period_id, p.reviewer_user_id, p.status,
                p.manager_rating, p.final_rating, p.final_rating_label,
                p.self_submitted_at, p.acknowledged_at, p.updated_at,
                e.employee_no, e.first_name, e.last_name, e.job_title,
                rp.name AS period_name, rp.period_type, rp.start_date AS period_start, rp.end_date AS period_end,
                rv.username AS reviewer_username
         FROM performance_reviews p
         JOIN employees e  ON e.id  = p.employee_id
         JOIN review_periods rp ON rp.id = p.period_id
         LEFT JOIN users rv ON rv.id = p.reviewer_user_id'
        . $whereSql
        . ' ORDER BY rp.end_date DESC, p.id DESC';

    $stmt = db()->prepare($reviewSql);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('Performance: review list fetch failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Rating label helper.
// ---------------------------------------------------------------------------
function perfRatingLabel(int $value): string
{
    static $map = [
        1 => 'Needs Improvement',
        2 => 'Developing',
        3 => 'Meets Expectations',
        4 => 'Exceeds Expectations',
        5 => 'Outstanding',
    ];
    return $map[$value] ?? (string) $value;
}

function perfStatusLabel(string $status): string
{
    static $labels = [
        'drafted'         => 'Drafted',
        'assigned'        => 'Assigned',
        'self_assessment' => 'Self-Assessment',
        'manager_review'  => 'Manager Review',
        'finalized'       => 'Finalized',
        'acknowledged'    => 'Acknowledged',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function perfStatusBadge(string $status): string
{
    $map = [
        'drafted'         => 'badge-secondary',
        'assigned'        => 'badge-info',
        'self_assessment' => 'badge-warning',
        'manager_review'  => 'badge-primary',
        'finalized'       => 'badge-success',
        'acknowledged'    => 'badge-success',
    ];
    $cls   = $map[$status] ?? 'badge-secondary';
    $label = perfStatusLabel($status);
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

function perfPeriodTypeBadge(string $type): string
{
    $map = [
        'annual'        => 'badge-info',
        'semi_annual'   => 'badge-primary',
        'quarterly'     => 'badge-warning',
        'probationary'  => 'badge-secondary',
    ];
    $cls   = $map[$type] ?? 'badge-secondary';
    $label = ucfirst(str_replace('_', ' ', $type));
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

function perfPeriodStatusBadge(string $status): string
{
    $map = [
        'planned' => 'badge-secondary',
        'open'    => 'badge-success',
        'closed'  => 'badge-danger',
    ];
    $cls = $map[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $cls . '">' . e(ucfirst($status)) . '</span>';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Performance Management</h1>
        <p class="page-subtitle">Manage review periods and employee performance reviews within your authorized scope</p>
    </div>
</div>

<?php /* Flash is rendered by header.php */ ?>

<!-- ======================================================================
     SECTION 1 — REVIEW PERIODS
     ====================================================================== -->
<section class="panel fade-in-up" id="section-periods">
    <div class="page-header" style="margin-bottom:1rem;">
        <div>
            <h2>Review Periods</h2>
            <p class="page-subtitle">Evaluation windows defined by HR</p>
        </div>
        <?php if ($isHR): ?>
        <button class="btn btn-primary btn-sm" type="button" id="btnNewPeriod">
            + New Period
        </button>
        <?php endif; ?>
    </div>

    <?php if ($isHR): ?>
    <!-- Create period form (HR only; hidden by default) -->
    <div id="createPeriodForm" hidden style="border-top:1px solid var(--border);padding-top:1.25rem;margin-bottom:1.25rem;">
        <h3 style="margin-bottom:1rem;font-size:.95rem;">Create Review Period</h3>
        <div class="form-grid" id="periodFormFields">
            <div class="form-group">
                <label for="pf_name">Period Name</label>
                <input id="pf_name" type="text" maxlength="120" placeholder="e.g. Annual Review 2026">
            </div>
            <div class="form-group">
                <label for="pf_type">Period Type</label>
                <select id="pf_type">
                    <option value="">Select type</option>
                    <option value="annual">Annual</option>
                    <option value="semi_annual">Semi-Annual</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="probationary">Probationary</option>
                </select>
            </div>
            <div class="form-group">
                <label for="pf_start">Start Date</label>
                <input id="pf_start" type="date">
            </div>
            <div class="form-group">
                <label for="pf_end">End Date</label>
                <input id="pf_end" type="date">
            </div>
            <div class="form-group">
                <label for="pf_status">Status</label>
                <select id="pf_status">
                    <option value="planned">Planned</option>
                    <option value="open">Open</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
        </div>
        <div id="periodFormError" class="alert alert-danger" hidden></div>
        <div class="form-actions">
            <button class="btn btn-primary" type="button" id="btnSavePeriod">Save Period</button>
            <button class="btn btn-outline" type="button" id="btnCancelPeriod">Cancel</button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$periods): ?>
    <p class="empty" style="padding:1.5rem 0;text-align:center;color:var(--text-muted);">No review periods defined yet.<?= $isHR ? ' Use the button above to create the first period.' : '' ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Period</th>
                <th>Type</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Status</th>
                <th>Reviews</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($periods as $period): ?>
            <?php
            // Count reviews in this period (within scope).
            $rcWhere  = ['rp.id = ?'];
            $rcParams = [(int) $period['id']];
            if ($isManager && $managerEmpId > 0) {
                $rcWhere[]  = '(e.manager_id = ? OR p.reviewer_user_id = ?)';
                $rcParams[] = $managerEmpId;
                $rcParams[] = (int) ($currentUser['id'] ?? 0);
            }
            $rcSql = 'SELECT COUNT(*) FROM performance_reviews p JOIN employees e ON e.id = p.employee_id JOIN review_periods rp ON rp.id = p.period_id WHERE ' . implode(' AND ', $rcWhere);
            $rcStmt = db()->prepare($rcSql);
            $rcStmt->execute($rcParams);
            $reviewCount = (int) $rcStmt->fetchColumn();
            ?>
            <tr>
                <td><strong><?= e($period['name']) ?></strong></td>
                <td><?= perfPeriodTypeBadge($period['period_type']) ?></td>
                <td><?= formatDate($period['start_date']) ?></td>
                <td><?= formatDate($period['end_date']) ?></td>
                <td><?= perfPeriodStatusBadge($period['status']) ?></td>
                <td><?= $reviewCount ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<!-- ======================================================================
     SECTION 2 — EMPLOYEE REVIEWS
     ====================================================================== -->
<section class="panel fade-in-up" style="margin-top:1rem;" id="section-reviews">
    <div class="page-header" style="margin-bottom:1rem;">
        <div>
            <h2>Employee Reviews</h2>
            <p class="page-subtitle">Assign and manage performance reviews within your authorized scope</p>
        </div>
        <?php if ($employees && $periods): ?>
        <button class="btn btn-primary btn-sm" type="button" id="btnNewReview">
            + Assign Review
        </button>
        <?php endif; ?>
    </div>

    <!-- Assign review form (hidden by default) -->
    <?php if ($employees && $periods): ?>
    <div id="createReviewForm" hidden style="border-top:1px solid var(--border);padding-top:1.25rem;margin-bottom:1.25rem;">
        <h3 style="margin-bottom:1rem;font-size:.95rem;">Assign Performance Review</h3>
        <div class="form-grid">
            <div class="form-group">
                <label for="rf_employee">Employee</label>
                <select id="rf_employee">
                    <option value="">Select employee</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int) $emp['id'] ?>">
                        <?= e($emp['employee_no'] . ' — ' . $emp['first_name'] . ' ' . $emp['last_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="rf_period">Review Period</label>
                <select id="rf_period">
                    <option value="">Select period</option>
                    <?php foreach ($periods as $period): if ($period['status'] === 'closed') continue; ?>
                    <option value="<?= (int) $period['id'] ?>">
                        <?= e($period['name']) ?> (<?= e(ucfirst($period['status'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="rf_reviewer">Reviewer <small style="font-weight:400;">(optional)</small></label>
                <select id="rf_reviewer">
                    <option value="">No reviewer assigned</option>
                    <?php foreach ($reviewerUsers as $ru): ?>
                    <?php $rname = trim(($ru['first_name'] ?? '') . ' ' . ($ru['last_name'] ?? '')) ?: $ru['username']; ?>
                    <option value="<?= (int) $ru['id'] ?>">
                        <?= e($rname . ' (' . $ru['username'] . ')') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div id="reviewGoalsContainer" hidden style="margin-top:.75rem;">
            <label style="display:block;font-weight:500;margin-bottom:.5rem;">Link Goals <small style="font-weight:400;">(optional — select employee first)</small></label>
            <div id="reviewGoalsList" style="display:flex;flex-wrap:wrap;gap:.5rem;min-height:2rem;align-items:flex-start;">
                <span style="color:var(--text-muted);font-size:.875rem;">Loading goals…</span>
            </div>
        </div>
        <div id="reviewFormError" class="alert alert-danger" hidden style="margin-top:.75rem;"></div>
        <div class="form-actions" style="margin-top:1rem;">
            <button class="btn btn-primary" type="button" id="btnSaveReview">Assign Review</button>
            <button class="btn btn-outline" type="button" id="btnCancelReview">Cancel</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="get" class="inline-form" style="flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
        <select name="period_id" aria-label="Filter by period">
            <option value="0">All periods</option>
            <?php foreach ($periods as $period): ?>
            <option value="<?= (int) $period['id'] ?>" <?= $filterPeriodId === (int) $period['id'] ? 'selected' : '' ?>>
                <?= e($period['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="employee_id" aria-label="Filter by employee">
            <option value="0">All employees</option>
            <?php foreach ($employees as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= $filterEmpId === (int) $emp['id'] ? 'selected' : '' ?>>
                <?= e($emp['employee_no'] . ' — ' . $emp['first_name'] . ' ' . $emp['last_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach ($validStatuses as $vs): ?>
            <option value="<?= $vs ?>" <?= $filterStatus === $vs ? 'selected' : '' ?>>
                <?= e(perfStatusLabel($vs)) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline btn-sm" type="submit">Filter</button>
        <a class="btn btn-outline btn-sm" href="performance.php">Clear</a>
    </form>

    <!-- Review list table -->
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Review Period</th>
                <th>Reviewer</th>
                <th>Status</th>
                <th>Updated</th>
                <th>Final Rating</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$reviews): ?>
            <tr><td colspan="7" class="empty">No reviews found. Use the filter above or assign a review to begin.</td></tr>
        <?php else: foreach ($reviews as $review): ?>
            <?php
            $empName     = e($review['employee_no'] . ' — ' . $review['first_name'] . ' ' . $review['last_name']);
            $reviewerName = $review['reviewer_username'] ? e($review['reviewer_username']) : '<span style="color:var(--text-muted)">—</span>';
            $isFinalized = in_array($review['status'], ['finalized', 'acknowledged'], true);
            ?>
            <tr>
                <td>
                    <strong><?= $empName ?></strong>
                    <?php if ($review['job_title']): ?>
                    <br><small><?= e($review['job_title']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= e($review['period_name']) ?></strong>
                    <br><?= perfPeriodTypeBadge($review['period_type']) ?>
                </td>
                <td><?= $reviewerName ?></td>
                <td><?= perfStatusBadge($review['status']) ?></td>
                <td><?= formatDate(substr($review['updated_at'], 0, 10)) ?></td>
                <td>
                    <?php if ($review['final_rating']): ?>
                        <strong><?= (int) $review['final_rating'] ?>/5</strong>
                        <?php if ($review['final_rating_label']): ?>
                        <br><small><?= e($review['final_rating_label']) ?></small>
                        <?php endif; ?>
                    <?php elseif ($review['manager_rating'] && !$isFinalized): ?>
                        <?= (int) $review['manager_rating'] ?>/5 <small style="color:var(--text-muted)">(draft)</small>
                    <?php else: ?>
                        <span style="color:var(--text-muted)">—</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <button class="btn btn-sm btn-outline"
                            type="button"
                            data-review-id="<?= (int) $review['id'] ?>"
                            onclick="openReviewDetail(<?= (int) $review['id'] ?>)">
                        View
                    </button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>

<!-- ======================================================================
     REVIEW DETAIL MODAL
     ====================================================================== -->
<div id="reviewModal" class="modal-backdrop" hidden aria-modal="true" role="dialog" aria-labelledby="reviewModalTitle">
    <div class="modal" style="max-width:720px;width:95%;">
        <div class="modal-header">
            <h3 id="reviewModalTitle">Review Detail</h3>
            <button type="button" class="btn btn-outline btn-sm" id="btnCloseModal">Close</button>
        </div>
        <div id="reviewModalBody" style="padding:1.25rem 0;">
            <p style="color:var(--text-muted);text-align:center;">Loading…</p>
        </div>
    </div>
</div>

<!-- ======================================================================
     INLINE STYLES (minimal, extending existing sheet)
     ====================================================================== -->
<style>
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9000;
    padding: 1rem;
}
.modal-backdrop[hidden] { display: none; }
.modal {
    background: var(--card-bg, #fff);
    border-radius: var(--radius, 8px);
    box-shadow: 0 8px 40px rgba(0,0,0,.18);
    max-height: 90vh;
    overflow-y: auto;
    padding: 1.5rem;
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: .5rem;
    border-bottom: 1px solid var(--border);
    padding-bottom: .75rem;
}
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .75rem 1.5rem;
    margin-bottom: 1rem;
}
@media (max-width: 600px) { .detail-grid { grid-template-columns: 1fr; } }
.detail-label {
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text-muted);
    margin-bottom: .2rem;
}
.detail-value { font-weight: 500; }
.goal-row {
    border: 1px solid var(--border);
    border-radius: var(--radius, 6px);
    padding: .75rem 1rem;
    margin-bottom: .5rem;
    background: var(--bg-subtle, #f9f9f9);
}
.goal-row-header { display: flex; justify-content: space-between; align-items: flex-start; gap: .5rem; }
.feedback-box {
    background: var(--bg-subtle, #f9f9f9);
    border: 1px solid var(--border);
    border-radius: var(--radius, 6px);
    padding: .75rem 1rem;
    margin-top: .5rem;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: .9rem;
}
.lifecycle-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1.25rem; border-top: 1px solid var(--border); padding-top: 1rem; }
.rating-select-inline { display: flex; align-items: center; gap: .5rem; margin-top: .25rem; }
.goal-check-item { display: flex; align-items: center; gap: .4rem; background: var(--bg-subtle,#f9f9f9); border: 1px solid var(--border); border-radius: var(--radius,6px); padding: .35rem .65rem; cursor: pointer; font-size: .875rem; user-select: none; }
.goal-check-item input { margin: 0; }
</style>

<!-- ======================================================================
     JAVASCRIPT — all interactions with the existing PerformanceController API
     ====================================================================== -->
<script>
(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* Helpers                                                              */
    /* ------------------------------------------------------------------ */
    const CSRF = <?= json_encode(csrf_token()) ?>;

    async function api(method, path, body) {
        const opts = {
            method,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            credentials: 'same-origin',
        };
        if (body !== undefined) opts.body = JSON.stringify(body);
        const res = await fetch(<?= json_encode(BASE_URL) ?> + '/api/v1/' + path, opts);
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw json;
        return json;
    }

    function showError(el, msg) {
        el.textContent = msg;
        el.hidden = false;
    }
    function clearError(el) {
        el.hidden = true;
        el.textContent = '';
    }

    function e(str) {
        const d = document.createElement('div');
        d.textContent = str ?? '';
        return d.innerHTML;
    }

    function statusBadge(status) {
        const map = {
            drafted: 'badge-secondary',
            assigned: 'badge-info',
            self_assessment: 'badge-warning',
            manager_review: 'badge-primary',
            finalized: 'badge-success',
            acknowledged: 'badge-success',
        };
        const labels = {
            drafted: 'Drafted', assigned: 'Assigned',
            self_assessment: 'Self-Assessment', manager_review: 'Manager Review',
            finalized: 'Finalized', acknowledged: 'Acknowledged',
        };
        const cls = map[status] || 'badge-secondary';
        const lbl = labels[status] || status.replace(/_/g, ' ');
        return `<span class="badge ${cls}">${e(lbl)}</span>`;
    }

    function ratingLabel(v) {
        const m = {1:'Needs Improvement',2:'Developing',3:'Meets Expectations',4:'Exceeds Expectations',5:'Outstanding'};
        return v ? (m[v] || v + '/5') : '—';
    }

    /* ------------------------------------------------------------------ */
    /* Period creation (HR only)                                            */
    /* ------------------------------------------------------------------ */
    const btnNewPeriod    = document.getElementById('btnNewPeriod');
    const createPeriodDiv = document.getElementById('createPeriodForm');
    const btnSavePeriod   = document.getElementById('btnSavePeriod');
    const btnCancelPeriod = document.getElementById('btnCancelPeriod');
    const periodFormError = document.getElementById('periodFormError');

    if (btnNewPeriod) {
        btnNewPeriod.addEventListener('click', () => {
            createPeriodDiv.hidden = false;
            btnNewPeriod.hidden = true;
        });
        btnCancelPeriod.addEventListener('click', () => {
            createPeriodDiv.hidden = true;
            btnNewPeriod.hidden = false;
            clearError(periodFormError);
        });
        btnSavePeriod.addEventListener('click', async () => {
            clearError(periodFormError);
            const name       = document.getElementById('pf_name').value.trim();
            const periodType = document.getElementById('pf_type').value;
            const startDate  = document.getElementById('pf_start').value;
            const endDate    = document.getElementById('pf_end').value;
            const status     = document.getElementById('pf_status').value;

            if (!name || !periodType || !startDate || !endDate) {
                showError(periodFormError, 'Name, type, start date and end date are all required.');
                return;
            }
            btnSavePeriod.disabled = true;
            try {
                await api('POST', 'admin/performance/periods', { name, period_type: periodType, start_date: startDate, end_date: endDate, status });
                location.reload();
            } catch (err) {
                const msg = err?.message || (err?.errors ? Object.values(err.errors).join(' ') : 'Could not create period.');
                showError(periodFormError, msg);
            } finally {
                btnSavePeriod.disabled = false;
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Review creation                                                      */
    /* ------------------------------------------------------------------ */
    const btnNewReview    = document.getElementById('btnNewReview');
    const createReviewDiv = document.getElementById('createReviewForm');
    const btnSaveReview   = document.getElementById('btnSaveReview');
    const btnCancelReview = document.getElementById('btnCancelReview');
    const reviewFormError = document.getElementById('reviewFormError');
    const rfEmployee      = document.getElementById('rf_employee');
    const rfPeriod        = document.getElementById('rf_period');
    const rfReviewer      = document.getElementById('rf_reviewer');
    const reviewGoalsCont = document.getElementById('reviewGoalsContainer');
    const reviewGoalsList = document.getElementById('reviewGoalsList');

    let goalOptions = []; // goals for selected employee

    if (btnNewReview) {
        btnNewReview.addEventListener('click', () => {
            createReviewDiv.hidden = false;
            btnNewReview.hidden = true;
        });
        btnCancelReview.addEventListener('click', () => {
            createReviewDiv.hidden = true;
            btnNewReview.hidden = false;
            clearError(reviewFormError);
            rfEmployee.value = '';
            if (rfPeriod) rfPeriod.value = '';
            if (rfReviewer) rfReviewer.value = '';
            reviewGoalsCont.hidden = true;
            goalOptions = [];
        });

        if (rfEmployee) {
            rfEmployee.addEventListener('change', async () => {
                const empId = parseInt(rfEmployee.value, 10);
                reviewGoalsCont.hidden = true;
                goalOptions = [];
                if (!empId) return;
                try {
                    const data = await api('GET', `admin/performance/options?employee_id=${empId}`);
                    goalOptions = data.data?.goals || [];
                    if (goalOptions.length === 0) {
                        reviewGoalsList.innerHTML = '<span style="color:var(--text-muted);font-size:.875rem;">No goals found for this employee.</span>';
                    } else {
                        reviewGoalsList.innerHTML = goalOptions.map(g =>
                            `<label class="goal-check-item">
                                <input type="checkbox" name="goal_ids" value="${g.id}">
                                ${e(g.title)}${g.status ? ` <span style="color:var(--text-muted);font-size:.8rem;">(${g.status.replace(/_/g,' ')})</span>` : ''}
                            </label>`
                        ).join('');
                    }
                    reviewGoalsCont.hidden = false;
                } catch (err) {
                    reviewGoalsCont.hidden = true;
                }
            });
        }

        if (btnSaveReview) {
            btnSaveReview.addEventListener('click', async () => {
                clearError(reviewFormError);
                const empId      = parseInt(rfEmployee.value, 10);
                const periodId   = parseInt(rfPeriod.value, 10);
                const reviewerId = rfReviewer ? (parseInt(rfReviewer.value, 10) || null) : null;

                if (!empId || !periodId) {
                    showError(reviewFormError, 'Select an employee and a review period.');
                    return;
                }

                const goalIds = reviewGoalsList
                    ? Array.from(reviewGoalsList.querySelectorAll('input[type=checkbox]:checked')).map(c => parseInt(c.value, 10))
                    : [];

                const payload = { employee_id: empId, period_id: periodId };
                if (reviewerId) payload.reviewer_user_id = reviewerId;
                if (goalIds.length) payload.goal_ids = goalIds;

                btnSaveReview.disabled = true;
                try {
                    await api('POST', 'admin/performance/reviews', payload);
                    location.reload();
                } catch (err) {
                    const msg = err?.message || (err?.errors ? Object.values(err.errors).join(' ') : 'Could not assign review.');
                    showError(reviewFormError, msg);
                } finally {
                    btnSaveReview.disabled = false;
                }
            });
        }
    }

    /* ------------------------------------------------------------------ */
    /* Review detail modal                                                  */
    /* ------------------------------------------------------------------ */
    const modal      = document.getElementById('reviewModal');
    const modalBody  = document.getElementById('reviewModalBody');
    const btnClose   = document.getElementById('btnCloseModal');

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    btnClose?.addEventListener('click', closeModal);
    modal?.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

    window.openReviewDetail = async function (reviewId) {
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        modalBody.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:2rem 0;">Loading…</p>';
        try {
            const data = await api('GET', `admin/performance/reviews/${reviewId}`);
            renderDetail(data.data, reviewId);
        } catch (err) {
            modalBody.innerHTML = `<p class="alert alert-danger">Could not load review: ${e(err?.message || 'Unknown error')}</p>`;
        }
    };

    function renderDetail(r, reviewId) {
        const isFinalized = r.status === 'finalized' || r.status === 'acknowledged';
        const emp         = r.employee || {};
        const period      = r.period   || {};

        // Status transition map (mirror of server-side TRANSITIONS constant).
        const transitions = {
            drafted:         ['assigned'],
            assigned:        ['drafted', 'self_assessment'],
            self_assessment: ['assigned', 'manager_review'],
            manager_review:  ['finalized'],
            finalized:       [],
            acknowledged:    [],
        };
        const nextStates = transitions[r.status] || [];

        let html = `
        <div class="detail-grid">
            <div>
                <div class="detail-label">Employee</div>
                <div class="detail-value">${e((emp.employee_no ? emp.employee_no + ' — ' : '') + (emp.name || ''))}</div>
                ${emp.job_title ? `<div style="color:var(--text-muted);font-size:.85rem;">${e(emp.job_title)}</div>` : ''}
            </div>
            <div>
                <div class="detail-label">Review Period</div>
                <div class="detail-value">${e(period.name || '—')}</div>
                <div style="color:var(--text-muted);font-size:.85rem;">${e(period.start_date || '')} — ${e(period.end_date || '')}</div>
            </div>
            <div>
                <div class="detail-label">Status</div>
                <div class="detail-value">${statusBadge(r.status)}</div>
            </div>
            <div>
                <div class="detail-label">Reviewer</div>
                <div class="detail-value">${e(r.reviewer_username || '—')}</div>
            </div>
            ${r.manager_rating ? `
            <div>
                <div class="detail-label">Manager Rating</div>
                <div class="detail-value">${e(r.manager_rating + '/5 — ' + ratingLabel(r.manager_rating))}</div>
            </div>` : ''}
            ${r.final_rating ? `
            <div>
                <div class="detail-label">Final Rating</div>
                <div class="detail-value">${e(r.final_rating + '/5 — ' + (r.final_rating_label || ratingLabel(r.final_rating)))}</div>
            </div>` : ''}
            ${r.self_submitted_at ? `
            <div>
                <div class="detail-label">Self-Assessment Submitted</div>
                <div class="detail-value">${e(r.self_submitted_at)}</div>
            </div>` : ''}
            ${r.acknowledged_at ? `
            <div>
                <div class="detail-label">Acknowledged At</div>
                <div class="detail-value">${e(r.acknowledged_at)}</div>
            </div>` : ''}
        </div>`;

        // Self-assessment (visible once finalized or in manager_review stage).
        if (r.self_assessment && !r.ratings_hidden) {
            const sa = r.self_assessment;
            html += `<div style="margin-bottom:1rem;">
                <div class="detail-label" style="margin-bottom:.4rem;">Self-Assessment</div>
                ${sa.strengths ? `<div style="margin-bottom:.35rem;"><strong>Strengths:</strong><div class="feedback-box">${e(sa.strengths)}</div></div>` : ''}
                ${sa.improvements ? `<div style="margin-bottom:.35rem;"><strong>Areas for Improvement:</strong><div class="feedback-box">${e(sa.improvements)}</div></div>` : ''}
                ${sa.comments ? `<div><strong>Comments:</strong><div class="feedback-box">${e(sa.comments)}</div></div>` : ''}
            </div>`;
        }

        // Manager feedback (visible once finalized).
        if (r.manager_feedback && !r.ratings_hidden) {
            html += `<div style="margin-bottom:1rem;">
                <div class="detail-label" style="margin-bottom:.4rem;">Manager Feedback</div>
                <div class="feedback-box">${e(r.manager_feedback)}</div>
            </div>`;
        }

        // Acknowledge note.
        if (r.acknowledge_note) {
            html += `<div style="margin-bottom:1rem;">
                <div class="detail-label" style="margin-bottom:.4rem;">Employee Note (Acknowledgement)</div>
                <div class="feedback-box">${e(r.acknowledge_note)}</div>
            </div>`;
        }

        // Goal results.
        if (r.goal_results && r.goal_results.length > 0) {
            html += `<div style="margin-bottom:1rem;">
                <div class="detail-label" style="margin-bottom:.5rem;">Linked Goals</div>`;
            r.goal_results.forEach(gr => {
                const ratingBadge = gr.rating
                    ? `<span class="badge badge-info">${e(gr.rating + '/5 — ' + ratingLabel(gr.rating))}</span>`
                    : `<span style="color:var(--text-muted);font-size:.85rem;">Not rated</span>`;
                html += `<div class="goal-row">
                    <div class="goal-row-header">
                        <strong>${e(gr.title)}</strong>
                        ${ratingBadge}
                    </div>
                    <div style="color:var(--text-muted);font-size:.85rem;margin-top:.25rem;">
                        Progress: ${gr.live_progress ?? gr.goal_progress_snapshot}%
                        · Status: ${e((gr.live_status || gr.goal_status_snapshot).replace(/_/g,' '))}
                        ${gr.due_date ? '· Due: ' + e(gr.due_date) : ''}
                    </div>
                    ${gr.result_notes ? `<div style="margin-top:.35rem;font-size:.875rem;">${e(gr.result_notes)}</div>` : ''}
                </div>`;
            });
            html += '</div>';
        }

        // ----------------------------------------------------------------
        // Editable fields: manager feedback + rating (for non-finalized).
        // ----------------------------------------------------------------
        if (!isFinalized) {
            html += `
            <div id="feedbackSection" style="border-top:1px solid var(--border);padding-top:1rem;margin-top:.5rem;">
                <h4 style="margin-bottom:.75rem;font-size:.9rem;">Manager Feedback &amp; Rating</h4>
                <div class="form-group">
                    <label for="md_feedback">Feedback</label>
                    <textarea id="md_feedback" rows="4" maxlength="5000">${e(r.manager_feedback || '')}</textarea>
                </div>
                <div class="form-group">
                    <label for="md_rating">Manager Rating</label>
                    <select id="md_rating">
                        <option value="">No rating</option>
                        ${[1,2,3,4,5].map(v => `<option value="${v}" ${r.manager_rating == v ? 'selected' : ''}>${v} — ${ratingLabel(v)}</option>`).join('')}
                    </select>
                </div>
                <div id="feedbackError" class="alert alert-danger" hidden></div>
                <div class="form-actions">
                    <button class="btn btn-outline btn-sm" type="button" id="btnSaveFeedback">Save Feedback</button>
                </div>
            </div>`;
        }

        // ----------------------------------------------------------------
        // Lifecycle transition actions.
        // ----------------------------------------------------------------
        if (nextStates.length > 0) {
            const transitionLabels = {
                assigned:        'Assign to Employee',
                drafted:         'Return to Draft',
                self_assessment: 'Open Self-Assessment',
                manager_review:  'Send to Manager Review',
                finalized:       'Finalize Review',
            };
            html += `<div class="lifecycle-actions">
                <span style="font-size:.85rem;font-weight:500;color:var(--text-muted);align-self:center;">Advance:</span>
                ${nextStates.map(s => {
                    const label = transitionLabels[s] || ('→ ' + s.replace(/_/g,' '));
                    const danger = s === 'finalized' ? ' btn-primary' : ' btn-outline';
                    return `<button class="btn btn-sm${danger}" type="button" data-transition="${e(s)}" id="btnTransition_${e(s)}">${e(label)}</button>`;
                }).join('')}
                <div id="transitionError" class="alert alert-danger" hidden style="margin-left:.5rem;width:100%;"></div>
            </div>`;
        }

        modalBody.innerHTML = html;

        // Bind feedback save.
        const btnSaveFeedback = document.getElementById('btnSaveFeedback');
        const feedbackError   = document.getElementById('feedbackError');
        if (btnSaveFeedback) {
            btnSaveFeedback.addEventListener('click', async () => {
                clearError(feedbackError);
                const feedback = document.getElementById('md_feedback').value.trim();
                const rating   = parseInt(document.getElementById('md_rating').value, 10) || null;
                if (!feedback && !rating) {
                    showError(feedbackError, 'Enter feedback or a rating before saving.');
                    return;
                }
                const payload = {};
                if (feedback) payload.manager_feedback = feedback;
                if (rating)   payload.manager_rating   = rating;
                btnSaveFeedback.disabled = true;
                try {
                    await api('PUT', `admin/performance/reviews/${reviewId}/manager-feedback`, payload);
                    openReviewDetail(reviewId); // refresh modal
                } catch (err) {
                    const msg = err?.message || (err?.errors ? Object.values(err.errors).join(' ') : 'Save failed.');
                    showError(feedbackError, msg);
                } finally {
                    btnSaveFeedback.disabled = false;
                }
            });
        }

        // Bind transition buttons.
        const transitionError = document.getElementById('transitionError');
        document.querySelectorAll('[data-transition]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const to = btn.getAttribute('data-transition');
                if (transitionError) clearError(transitionError);

                // Guard: finalize requires a manager rating.
                if (to === 'finalized' && !r.manager_rating) {
                    const ratingEl = document.getElementById('md_rating');
                    if (ratingEl && !parseInt(ratingEl.value, 10)) {
                        if (transitionError) showError(transitionError, 'A manager rating (1–5) is required before the review can be finalized. Set it in the Feedback section above.');
                        return;
                    }
                }

                btn.disabled = true;
                try {
                    await api('PATCH', `admin/performance/reviews/${reviewId}`, { status: to });
                    openReviewDetail(reviewId); // refresh modal
                } catch (err) {
                    const msg = err?.message || (err?.errors ? Object.values(err.errors).join(' ') : 'Transition failed.');
                    if (transitionError) showError(transitionError, msg);
                    else alert(msg);
                } finally {
                    btn.disabled = false;
                }
            });
        });
    }

})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
