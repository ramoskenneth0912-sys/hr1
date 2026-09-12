<?php
/**
 * HR / Admin — Competencies Management
 * Phase 8C: Core HCM Competencies module for HR and Manager users.
 *
 * Counterpart to the employee's modules/employee/competencies.php (My Competencies)
 * page, built on top of the Phase 3A Competencies architecture.
 *
 * Security:
 *  - requireHRorManager() gates the page to HR/Manager roles only.
 *  - All write operations go through the existing CompetenciesController API
 *    endpoints which enforce RBAC, manager scope, prepared statements, and
 *    validation server-side.
 *  - CSRF tokens are embedded in every form/AJAX call.
 *  - All HTML output is escaped via e().
 */
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
requireNotApplicant();

$currentUser   = getCurrentUser();
$isManager     = isManager();
$isHR          = isHR();
$managerEmpId  = (int) ($currentUser['employee_id'] ?? 0);
$pageTitle     = 'Competencies Management';
$currentModule = 'competencies';

// ---------------------------------------------------------------------------
// 1. Rating options (1-5 scale from rating_options where scale_code='competency')
// ---------------------------------------------------------------------------
$ratingScale = [];
try {
    $stmt = db()->query("SELECT value, label FROM rating_options WHERE scale_code = 'competency' ORDER BY sort_order, value");
    foreach ($stmt->fetchAll() as $row) {
        $ratingScale[(int) $row['value']] = $row['label'];
    }
} catch (Throwable $e) {
    error_log('Competencies: rating scale fetch failed: ' . $e->getMessage());
}
if (!$ratingScale) {
    $ratingScale = [
        1 => 'Needs Improvement',
        2 => 'Developing',
        3 => 'Meets Expectations',
        4 => 'Exceeds Expectations',
        5 => 'Outstanding',
    ];
}

// ---------------------------------------------------------------------------
// 2. Scoped employees (HR = all non-terminated; Manager = direct reports)
// ---------------------------------------------------------------------------
$employees = [];
try {
    $empSql    = 'SELECT id, employee_no, first_name, last_name, job_title FROM employees WHERE status != \'terminated\'';
    $empParams = [];
    if ($isManager && $managerEmpId > 0) {
        $empSql    .= ' AND manager_id = ?';
        $empParams[] = $managerEmpId;
    }
    $empStmt = db()->prepare($empSql . ' ORDER BY last_name, first_name');
    $empStmt->execute($empParams);
    $employees = $empStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Competencies: employee fetch failed: ' . $e->getMessage());
}
$employeeIds = array_map(static fn(array $r): int => (int) $r['id'], $employees);

// ---------------------------------------------------------------------------
// 3. Competency Catalog Query & Filters
// ---------------------------------------------------------------------------
$catStatus   = trim((string) ($_GET['cat_status']   ?? 'all'));
$catCategory = trim((string) ($_GET['cat_category'] ?? ''));
$catQ        = trim((string) ($_GET['cat_q']        ?? ''));

$catWhere  = [];
$catParams = [];

if ($catStatus === 'active') {
    $catWhere[] = 'c.is_active = 1';
} elseif ($catStatus === 'archived') {
    $catWhere[] = 'c.is_active = 0';
}
if ($catCategory !== '') {
    $catWhere[]  = 'c.category = ?';
    $catParams[] = $catCategory;
}
if ($catQ !== '') {
    $catWhere[]  = '(c.name LIKE ? OR c.category LIKE ? OR c.description LIKE ?)';
    $catParams[] = '%' . $catQ . '%';
    $catParams[] = '%' . $catQ . '%';
    $catParams[] = '%' . $catQ . '%';
}

$catWhereSql = $catWhere ? ' WHERE ' . implode(' AND ', $catWhere) : '';
$catSql = 'SELECT c.*, u.username AS creator_username,
                  (SELECT COUNT(*) FROM employee_competencies ec WHERE ec.competency_id = c.id) AS assigned_count
           FROM competencies c
           LEFT JOIN users u ON u.id = c.created_by'
        . $catWhereSql . ' ORDER BY c.is_active DESC, c.name ASC';

$catalog = [];
try {
    $catStmt = db()->prepare($catSql);
    $catStmt->execute($catParams);
    $catalog = $catStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Competencies: catalog fetch failed: ' . $e->getMessage());
}

// Distinct categories for catalog filter dropdown
$categories = [];
try {
    $categories = db()->query("SELECT DISTINCT category FROM competencies WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('Competencies: categories fetch failed: ' . $e->getMessage());
}

// Active competencies for assignment creation & assignment filter dropdown
$activeCompetencies = [];
try {
    $activeCompetencies = db()->query('SELECT id, name, category FROM competencies WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {
    error_log('Competencies: active competencies fetch failed: ' . $e->getMessage());
}

// All competencies (for assignment filter dropdown)
$allCompetenciesForFilter = [];
try {
    $allCompetenciesForFilter = db()->query('SELECT id, name, is_active FROM competencies ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {
    error_log('Competencies: all competencies fetch failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 4. Employee Assignments Query & Filters
// ---------------------------------------------------------------------------
$filterEmpId  = (int) ($_GET['filter_employee_id']   ?? 0);
$filterCompId = (int) ($_GET['filter_competency_id'] ?? 0);
$filterStatus = trim((string) ($_GET['filter_status'] ?? ''));

if ($filterEmpId > 0 && !in_array($filterEmpId, $employeeIds, true)) {
    // Prevent out-of-scope employee ID filtering
    $filterEmpId = 0;
}

$asgWhere  = [];
$asgParams = [];

if ($isManager && $managerEmpId > 0) {
    $asgWhere[]  = 'e.manager_id = ?';
    $asgParams[] = $managerEmpId;
}
if ($filterEmpId > 0) {
    $asgWhere[]  = 'ec.employee_id = ?';
    $asgParams[] = $filterEmpId;
}
if ($filterCompId > 0) {
    $asgWhere[]  = 'ec.competency_id = ?';
    $asgParams[] = $filterCompId;
}
if (in_array($filterStatus, ['active', 'inactive'], true)) {
    $asgWhere[]  = 'ec.status = ?';
    $asgParams[] = $filterStatus;
}

$asgWhereSql = $asgWhere ? ' WHERE ' . implode(' AND ', $asgWhere) : '';
$asgSql = 'SELECT ec.*, c.name AS competency_name, c.category AS competency_category, c.is_active AS competency_is_active,
                  e.employee_no, e.first_name, e.last_name, e.job_title,
                  u.username AS evaluator_username,
                  ee.first_name AS evaluator_first_name, ee.last_name AS evaluator_last_name
           FROM employee_competencies ec
           JOIN competencies c ON c.id = ec.competency_id
           JOIN employees e ON e.id = ec.employee_id
           LEFT JOIN users u ON u.id = ec.evaluated_by
           LEFT JOIN employees ee ON ee.id = u.employee_id'
        . $asgWhereSql . ' ORDER BY e.last_name ASC, c.name ASC, ec.id DESC';

$assignments = [];
try {
    $asgStmt = db()->prepare($asgSql);
    $asgStmt->execute($asgParams);
    $assignments = $asgStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Competencies: assignments fetch failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------------
function compCatalogStatusBadge(bool $isActive): string
{
    return $isActive
        ? '<span class="badge badge-success">Active</span>'
        : '<span class="badge badge-secondary">Archived</span>';
}

function compAssignmentStatusBadge(string $status): string
{
    return $status === 'active'
        ? '<span class="badge badge-success">Active</span>'
        : '<span class="badge badge-secondary">Inactive</span>';
}

function compRatingBadge(?int $level, array $scale): string
{
    if ($level === null) {
        return '<span style="color:var(--text-muted);font-size:.85rem;">Not Evaluated</span>';
    }
    $label = $scale[$level] ?? ($level . '/5');
    $cls = 'badge-info';
    if ($level >= 4) {
        $cls = 'badge-success';
    } elseif ($level <= 2) {
        $cls = 'badge-warning';
    }
    return '<span class="badge ' . $cls . '">' . (int) $level . ' · ' . e($label) . '</span>';
}

function compGapBadge(?int $current, int $required): string
{
    if ($current === null) {
        return '<span class="badge badge-secondary">Pending</span>';
    }
    $gap = $required - $current;
    if ($gap <= 0) {
        return '<span class="badge badge-success">Target Met</span>';
    }
    return '<span class="badge badge-warning">Gap (-' . $gap . ')</span>';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Competencies Management</h1>
        <p class="page-subtitle">Standardize organizational competencies and evaluate employee capabilities within your authorized scope</p>
    </div>
</div>

<!-- ======================================================================
     SECTION 1 — COMPETENCY CATALOG
     ====================================================================== -->
<section class="panel fade-in-up" id="section-catalog">
    <div class="page-header" style="margin-bottom:1rem;">
        <div>
            <h2>Competency Catalog</h2>
            <p class="page-subtitle">Organizational competency library</p>
        </div>
        <?php if ($isHR): ?>
        <button class="btn btn-primary btn-sm" type="button" id="btnNewCompetency">
            + New Competency
        </button>
        <?php endif; ?>
    </div>

    <?php if ($isHR): ?>
    <!-- Create competency form (HR only; toggled) -->
    <div id="createCompetencyForm" hidden style="border-top:1px solid var(--border);padding-top:1.25rem;margin-bottom:1.25rem;">
        <h3 style="margin-bottom:1rem;font-size:.95rem;">Create New Competency</h3>
        <div class="form-grid">
            <div class="form-group">
                <label for="cf_name">Competency Name <span style="color:var(--danger,#e53e3e)">*</span></label>
                <input id="cf_name" type="text" maxlength="120" placeholder="e.g. Problem Solving, Negotiation" required>
            </div>
            <div class="form-group">
                <label for="cf_category">Category</label>
                <input id="cf_category" type="text" maxlength="60" list="category_suggestions" placeholder="e.g. Technical, Behavioral, Leadership">
                <datalist id="category_suggestions">
                    <option value="Technical">
                    <option value="Behavioral">
                    <option value="Leadership">
                    <option value="Customer Focus">
                    <option value="Communication">
                    <option value="Operations">
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label for="cf_status">Status</label>
                <select id="cf_status">
                    <option value="1">Active</option>
                    <option value="0">Archived</option>
                </select>
            </div>
            <div class="form-group form-group-full">
                <label for="cf_description">Description</label>
                <textarea id="cf_description" rows="3" maxlength="4000" placeholder="Define the competency scope, expectations, or key behavioral indicators..."></textarea>
            </div>
        </div>
        <div id="createCompetencyError" class="alert alert-danger" hidden style="margin-top:.75rem;"></div>
        <div class="form-actions" style="margin-top:1rem;">
            <button class="btn btn-primary" type="button" id="btnSaveCompetency">Save Competency</button>
            <button class="btn btn-outline" type="button" id="btnCancelCompetency">Cancel</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Catalog filter bar -->
    <form method="get" class="inline-form" style="flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
        <!-- Preserve assignment filters when filtering catalog -->
        <?php if ($filterEmpId > 0): ?><input type="hidden" name="filter_employee_id" value="<?= $filterEmpId ?>"><?php endif; ?>
        <?php if ($filterCompId > 0): ?><input type="hidden" name="filter_competency_id" value="<?= $filterCompId ?>"><?php endif; ?>
        <?php if ($filterStatus !== ''): ?><input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>"><?php endif; ?>

        <select name="cat_status" aria-label="Filter catalog by status">
            <option value="all" <?= $catStatus === 'all' ? 'selected' : '' ?>>All statuses</option>
            <option value="active" <?= $catStatus === 'active' ? 'selected' : '' ?>>Active only</option>
            <option value="archived" <?= $catStatus === 'archived' ? 'selected' : '' ?>>Archived only</option>
        </select>
        <?php if ($categories): ?>
        <select name="cat_category" aria-label="Filter catalog by category">
            <option value="">All categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= e($cat) ?>" <?= $catCategory === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <input type="search" name="cat_q" value="<?= e($catQ) ?>" placeholder="Search competencies..." style="max-width:200px;">
        <button class="btn btn-outline btn-sm" type="submit">Filter Catalog</button>
        <?php if ($catStatus !== 'all' || $catCategory !== '' || $catQ !== ''): ?>
        <a class="btn btn-outline btn-sm" href="competencies.php<?= ($filterEmpId > 0 || $filterCompId > 0 || $filterStatus !== '') ? '?filter_employee_id=' . $filterEmpId . '&filter_competency_id=' . $filterCompId . '&filter_status=' . urlencode($filterStatus) : '' ?>">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Catalog table -->
    <table class="data-table">
        <thead>
            <tr>
                <th>Competency</th>
                <th>Category</th>
                <th>Description</th>
                <th>Status</th>
                <th>Assigned</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$catalog): ?>
            <tr><td colspan="6" class="empty">No competencies found.<?= $isHR ? ' Use the button above to add the first competency.' : '' ?></td></tr>
        <?php else: foreach ($catalog as $comp): ?>
            <?php $isActive = (int) $comp['is_active'] === 1; ?>
            <tr>
                <td>
                    <strong><?= e($comp['name']) ?></strong>
                </td>
                <td>
                    <?php if ($comp['category']): ?>
                    <span class="badge badge-info"><?= e($comp['category']) ?></span>
                    <?php else: ?>
                    <span style="color:var(--text-muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="max-width:320px;">
                    <?php if ($comp['description']): ?>
                    <span title="<?= e($comp['description']) ?>">
                        <?= e(mb_strimwidth($comp['description'], 0, 90, '…')) ?>
                    </span>
                    <?php else: ?>
                    <span style="color:var(--text-muted)">—</span>
                    <?php endif; ?>
                </td>
                <td><?= compCatalogStatusBadge($isActive) ?></td>
                <td><?= (int) $comp['assigned_count'] ?></td>
                <td class="actions">
                    <?php if ($isHR): ?>
                    <button class="btn btn-sm btn-outline btn-edit-comp"
                            type="button"
                            data-id="<?= (int) $comp['id'] ?>"
                            data-name="<?= e($comp['name']) ?>"
                            data-category="<?= e($comp['category'] ?? '') ?>"
                            data-description="<?= e($comp['description'] ?? '') ?>"
                            data-active="<?= $isActive ? '1' : '0' ?>">
                        Edit
                    </button>
                    <?php if ($isActive): ?>
                    <button class="btn btn-sm btn-outline btn-archive-comp"
                            type="button"
                            data-id="<?= (int) $comp['id'] ?>"
                            data-name="<?= e($comp['name']) ?>">
                        Archive
                    </button>
                    <?php else: ?>
                    <button class="btn btn-sm btn-outline btn-restore-comp"
                            type="button"
                            data-id="<?= (int) $comp['id'] ?>"
                            data-name="<?= e($comp['name']) ?>">
                        Restore
                    </button>
                    <?php endif; ?>
                    <?php else: ?>
                    <button class="btn btn-sm btn-outline btn-view-comp"
                            type="button"
                            data-id="<?= (int) $comp['id'] ?>"
                            data-name="<?= e($comp['name']) ?>"
                            data-category="<?= e($comp['category'] ?? '') ?>"
                            data-description="<?= e($comp['description'] ?? '') ?>"
                            data-active="<?= $isActive ? '1' : '0' ?>"
                            data-count="<?= (int) $comp['assigned_count'] ?>">
                        View
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<!-- ======================================================================
     SECTION 2 — EMPLOYEE ASSIGNMENTS
     ====================================================================== -->
<section class="panel fade-in-up" style="margin-top:1rem;" id="section-assignments">
    <div class="page-header" style="margin-bottom:1rem;">
        <div>
            <h2>Employee Competency Assignments</h2>
            <p class="page-subtitle">Track required target proficiency and evaluation results within your authorized scope</p>
        </div>
        <?php if ($isHR && $activeCompetencies && $employees): ?>
        <button class="btn btn-primary btn-sm" type="button" id="btnNewAssignment">
            + Assign Competency
        </button>
        <?php endif; ?>
    </div>

    <?php if ($isHR && $activeCompetencies && $employees): ?>
    <!-- Assign competency form (HR only; toggled) -->
    <div id="assignCompetencyForm" hidden style="border-top:1px solid var(--border);padding-top:1.25rem;margin-bottom:1.25rem;">
        <h3 style="margin-bottom:1rem;font-size:.95rem;">Assign Competency to Employee</h3>
        <div class="form-grid">
            <div class="form-group">
                <label for="af_employee">Employee <span style="color:var(--danger,#e53e3e)">*</span></label>
                <select id="af_employee" required>
                    <option value="">Select employee</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int) $emp['id'] ?>">
                        <?= e($emp['employee_no'] . ' — ' . $emp['first_name'] . ' ' . $emp['last_name']) ?><?= $emp['job_title'] ? ' (' . e($emp['job_title']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="af_competency">Competency <span style="color:var(--danger,#e53e3e)">*</span></label>
                <select id="af_competency" required>
                    <option value="">Select competency</option>
                    <?php foreach ($activeCompetencies as $ac): ?>
                    <option value="<?= (int) $ac['id'] ?>">
                        <?= e($ac['name']) ?><?= $ac['category'] ? ' [' . e($ac['category']) . ']' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="af_required">Required Target Level <span style="color:var(--danger,#e53e3e)">*</span></label>
                <select id="af_required" required>
                    <?php foreach ($ratingScale as $lvl => $lbl): ?>
                    <option value="<?= $lvl ?>" <?= $lvl === 3 ? 'selected' : '' ?>>
                        <?= $lvl ?> — <?= e($lbl) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="af_current">Initial Current Level <small style="font-weight:400;">(optional)</small></label>
                <select id="af_current">
                    <option value="">Not evaluated yet</option>
                    <?php foreach ($ratingScale as $lvl => $lbl): ?>
                    <option value="<?= $lvl ?>">
                        <?= $lvl ?> — <?= e($lbl) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="af_status">Assignment Status</label>
                <select id="af_status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="form-group form-group-full">
                <label for="af_notes">Evaluation Notes <small style="font-weight:400;">(optional)</small></label>
                <textarea id="af_notes" rows="2" maxlength="2000" placeholder="Notes, baseline observations, or development targets..."></textarea>
            </div>
        </div>
        <div id="assignCompetencyError" class="alert alert-danger" hidden style="margin-top:.75rem;"></div>
        <div class="form-actions" style="margin-top:1rem;">
            <button class="btn btn-primary" type="button" id="btnSaveAssignment">Assign Competency</button>
            <button class="btn btn-outline" type="button" id="btnCancelAssignment">Cancel</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Assignments filter bar -->
    <form method="get" class="inline-form" style="flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
        <!-- Preserve catalog filters when filtering assignments -->
        <?php if ($catStatus !== 'all'): ?><input type="hidden" name="cat_status" value="<?= e($catStatus) ?>"><?php endif; ?>
        <?php if ($catCategory !== ''): ?><input type="hidden" name="cat_category" value="<?= e($catCategory) ?>"><?php endif; ?>
        <?php if ($catQ !== ''): ?><input type="hidden" name="cat_q" value="<?= e($catQ) ?>"><?php endif; ?>

        <select name="filter_employee_id" aria-label="Filter assignments by employee">
            <option value="0">All employees</option>
            <?php foreach ($employees as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= $filterEmpId === (int) $emp['id'] ? 'selected' : '' ?>>
                <?= e($emp['employee_no'] . ' — ' . $emp['first_name'] . ' ' . $emp['last_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="filter_competency_id" aria-label="Filter assignments by competency">
            <option value="0">All competencies</option>
            <?php foreach ($allCompetenciesForFilter as $ac): ?>
            <option value="<?= (int) $ac['id'] ?>" <?= $filterCompId === (int) $ac['id'] ? 'selected' : '' ?>>
                <?= e($ac['name']) ?><?= (int) $ac['is_active'] !== 1 ? ' (archived)' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="filter_status" aria-label="Filter assignments by status">
            <option value="">All statuses</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active only</option>
            <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive only</option>
        </select>
        <button class="btn btn-outline btn-sm" type="submit">Filter Assignments</button>
        <?php if ($filterEmpId > 0 || $filterCompId > 0 || $filterStatus !== ''): ?>
        <a class="btn btn-outline btn-sm" href="competencies.php<?= ($catStatus !== 'all' || $catCategory !== '' || $catQ !== '') ? '?cat_status=' . urlencode($catStatus) . '&cat_category=' . urlencode($catCategory) . '&cat_q=' . urlencode($catQ) : '' ?>">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Assignments table -->
    <table class="data-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Competency</th>
                <th>Target</th>
                <th>Assessed</th>
                <th>Gap / Result</th>
                <th>Evaluator</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$assignments): ?>
            <tr><td colspan="8" class="empty">No employee competency assignments found.<?= $isHR ? ' Use the button above to assign a competency.' : '' ?></td></tr>
        <?php else: foreach ($assignments as $asg): ?>
            <?php
            $currLevel = $asg['current_level'] !== null ? (int) $asg['current_level'] : null;
            $reqLevel  = (int) $asg['required_level'];
            $evaluator = trim(($asg['evaluator_first_name'] ?? '') . ' ' . ($asg['evaluator_last_name'] ?? ''));
            if ($evaluator === '' && !empty($asg['evaluator_username'])) {
                $evaluator = $asg['evaluator_username'];
            }
            ?>
            <tr>
                <td>
                    <strong><?= e($asg['employee_no'] . ' — ' . $asg['first_name'] . ' ' . $asg['last_name']) ?></strong>
                    <?php if ($asg['job_title']): ?>
                    <br><small style="color:var(--text-muted)"><?= e($asg['job_title']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= e($asg['competency_name']) ?></strong>
                    <?php if ($asg['competency_category']): ?>
                    <br><small class="badge badge-secondary"><?= e($asg['competency_category']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= compRatingBadge($reqLevel, $ratingScale) ?></td>
                <td><?= compRatingBadge($currLevel, $ratingScale) ?></td>
                <td><?= compGapBadge($currLevel, $reqLevel) ?></td>
                <td>
                    <?php if ($evaluator): ?>
                    <?= e($evaluator) ?>
                    <?php if ($asg['evaluated_at']): ?>
                    <br><small style="color:var(--text-muted)"><?= formatDate(substr($asg['evaluated_at'], 0, 10)) ?></small>
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="color:var(--text-muted)">—</span>
                    <?php endif; ?>
                </td>
                <td><?= compAssignmentStatusBadge($asg['status']) ?></td>
                <td class="actions">
                    <button class="btn btn-sm btn-outline btn-edit-assignment"
                            type="button"
                            data-id="<?= (int) $asg['id'] ?>"
                            data-emp-name="<?= e($asg['employee_no'] . ' — ' . $asg['first_name'] . ' ' . $asg['last_name']) ?>"
                            data-comp-name="<?= e($asg['competency_name']) ?>"
                            data-required="<?= $reqLevel ?>"
                            data-current="<?= $currLevel !== null ? $currLevel : '' ?>"
                            data-status="<?= e($asg['status']) ?>"
                            data-notes="<?= e($asg['notes'] ?? '') ?>"
                            data-evaluator="<?= e($evaluator) ?>"
                            data-evaluated-at="<?= e($asg['evaluated_at'] ?? '') ?>">
                        <?= $isHR ? 'Edit' : 'Evaluate' ?>
                    </button>
                    <?php if ($isHR): ?>
                    <button class="btn btn-sm btn-outline btn-delete-assignment"
                            type="button"
                            data-id="<?= (int) $asg['id'] ?>"
                            data-emp-name="<?= e($asg['first_name'] . ' ' . $asg['last_name']) ?>"
                            data-comp-name="<?= e($asg['competency_name']) ?>">
                        Remove
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<!-- ======================================================================
     MODAL 1: EDIT COMPETENCY (HR Only)
     ====================================================================== -->
<div id="modalEditCompetency" class="modal-backdrop" hidden aria-modal="true" role="dialog" aria-labelledby="modalEditCompetencyTitle">
    <div class="modal" style="max-width:560px;width:95%;">
        <div class="modal-header">
            <h3 id="modalEditCompetencyTitle">Edit Competency</h3>
            <button type="button" class="btn btn-outline btn-sm btn-close-modal">Close</button>
        </div>
        <div style="padding:1rem 0;">
            <input type="hidden" id="ecf_id">
            <div class="form-group" style="margin-bottom:1rem;">
                <label for="ecf_name">Competency Name <span style="color:var(--danger,#e53e3e)">*</span></label>
                <input id="ecf_name" type="text" maxlength="120" required>
            </div>
            <div class="form-group" style="margin-bottom:1rem;">
                <label for="ecf_category">Category</label>
                <input id="ecf_category" type="text" maxlength="60" list="category_suggestions">
            </div>
            <div class="form-group" style="margin-bottom:1rem;">
                <label for="ecf_status">Status</label>
                <select id="ecf_status">
                    <option value="1">Active</option>
                    <option value="0">Archived</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:1rem;">
                <label for="ecf_description">Description</label>
                <textarea id="ecf_description" rows="4" maxlength="4000"></textarea>
            </div>
            <div id="editCompetencyError" class="alert alert-danger" hidden></div>
            <div class="form-actions" style="margin-top:1.25rem;">
                <button class="btn btn-primary" type="button" id="btnUpdateCompetency">Save Changes</button>
                <button class="btn btn-outline btn-close-modal" type="button">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================
     MODAL 2: VIEW COMPETENCY (Manager / View mode)
     ====================================================================== -->
<div id="modalViewCompetency" class="modal-backdrop" hidden aria-modal="true" role="dialog" aria-labelledby="modalViewCompetencyTitle">
    <div class="modal" style="max-width:560px;width:95%;">
        <div class="modal-header">
            <h3 id="modalViewCompetencyTitle">Competency Details</h3>
            <button type="button" class="btn btn-outline btn-sm btn-close-modal">Close</button>
        </div>
        <div style="padding:1rem 0;">
            <div class="detail-grid">
                <div>
                    <div class="detail-label">Competency</div>
                    <div class="detail-value" id="vcf_name">—</div>
                </div>
                <div>
                    <div class="detail-label">Category</div>
                    <div class="detail-value" id="vcf_category">—</div>
                </div>
                <div>
                    <div class="detail-label">Status</div>
                    <div class="detail-value" id="vcf_status">—</div>
                </div>
                <div>
                    <div class="detail-label">Assigned Employees</div>
                    <div class="detail-value" id="vcf_count">0</div>
                </div>
            </div>
            <div style="margin-top:.75rem;">
                <div class="detail-label">Description</div>
                <div class="feedback-box" id="vcf_description">No description provided.</div>
            </div>
            <div class="form-actions" style="margin-top:1.25rem;">
                <button class="btn btn-outline btn-close-modal" type="button">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================
     MODAL 3: EVALUATE / EDIT ASSIGNMENT (HR & Manager)
     ====================================================================== -->
<div id="modalEditAssignment" class="modal-backdrop" hidden aria-modal="true" role="dialog" aria-labelledby="modalEditAssignmentTitle">
    <div class="modal" style="max-width:620px;width:95%;">
        <div class="modal-header">
            <h3 id="modalEditAssignmentTitle"><?= $isHR ? 'Edit Competency Assignment' : 'Evaluate Employee Competency' ?></h3>
            <button type="button" class="btn btn-outline btn-sm btn-close-modal">Close</button>
        </div>
        <div style="padding:1rem 0;">
            <input type="hidden" id="eaf_id">
            <div class="detail-grid" style="margin-bottom:1.25rem;">
                <div>
                    <div class="detail-label">Employee</div>
                    <div class="detail-value" id="eaf_emp_name">—</div>
                </div>
                <div>
                    <div class="detail-label">Competency</div>
                    <div class="detail-value" id="eaf_comp_name">—</div>
                </div>
            </div>

            <?php if ($isHR): ?>
            <!-- HR can update required level -->
            <div class="form-group" style="margin-bottom:1rem;">
                <label for="eaf_required">Required Target Level <span style="color:var(--danger,#e53e3e)">*</span></label>
                <select id="eaf_required" required>
                    <?php foreach ($ratingScale as $lvl => $lbl): ?>
                    <option value="<?= $lvl ?>"><?= $lvl ?> — <?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <!-- Manager views required level as read-only -->
            <div style="margin-bottom:1rem;">
                <div class="detail-label">Required Target Level</div>
                <div class="detail-value" id="eaf_required_display">—</div>
            </div>
            <?php endif; ?>

            <!-- Both HR and Manager can evaluate current level -->
            <div class="form-group" style="margin-bottom:1rem;">
                <label for="eaf_current">Current Evaluated Level</label>
                <select id="eaf_current">
                    <option value="">Not evaluated yet</option>
                    <?php foreach ($ratingScale as $lvl => $lbl): ?>
                    <option value="<?= $lvl ?>"><?= $lvl ?> — <?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:1rem;">
                <label for="eaf_status">Assignment Status</label>
                <select id="eaf_status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:1rem;">
                <label for="eaf_notes">Evaluation Notes</label>
                <textarea id="eaf_notes" rows="3" maxlength="2000" placeholder="Observations, evidence, feedback, or development recommendations..."></textarea>
            </div>

            <div id="eaf_last_eval" style="margin-bottom:1rem;color:var(--text-muted);font-size:.85rem;" hidden>
                Last evaluated by <span id="eaf_eval_by"></span> on <span id="eaf_eval_at"></span>.
            </div>

            <div id="editAssignmentError" class="alert alert-danger" hidden></div>

            <div class="form-actions" style="margin-top:1.25rem;">
                <button class="btn btn-primary" type="button" id="btnUpdateAssignment">Save Changes</button>
                <button class="btn btn-outline btn-close-modal" type="button">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================
     INLINE STYLES (modal, detail grid, consistent with performance.php)
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
</style>

<!-- ======================================================================
     JAVASCRIPT — API interactions with CompetenciesController endpoints
     ====================================================================== -->
<script>
(function () {
    'use strict';

    const CSRF = <?= json_encode(csrf_token()) ?>;
    const IS_HR = <?= json_encode($isHR) ?>;
    const RATING_SCALE = <?= json_encode($ratingScale) ?>;

    async function api(method, path, body) {
        const opts = {
            method: method,
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

    function closeModal(modal) {
        if (modal) {
            modal.hidden = true;
            document.body.style.overflow = '';
        }
    }
    function openModal(modal) {
        if (modal) {
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
        }
    }

    // Bind all close buttons
    document.querySelectorAll('.btn-close-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal-backdrop');
            closeModal(modal);
        });
    });
    document.querySelectorAll('.modal-backdrop').forEach(mb => {
        mb.addEventListener('click', e => {
            if (e.target === mb) closeModal(mb);
        });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop:not([hidden])').forEach(closeModal);
        }
    });

    /* ------------------------------------------------------------------ */
    /* 1. Competency Catalog — Create (HR Only)                           */
    /* ------------------------------------------------------------------ */
    const btnNewComp     = document.getElementById('btnNewCompetency');
    const createCompForm = document.getElementById('createCompetencyForm');
    const btnCancelComp  = document.getElementById('btnCancelCompetency');
    const btnSaveComp    = document.getElementById('btnSaveCompetency');
    const createCompErr  = document.getElementById('createCompetencyError');

    if (btnNewComp && createCompForm) {
        btnNewComp.addEventListener('click', () => {
            createCompForm.hidden = false;
            btnNewComp.hidden = true;
        });
        btnCancelComp.addEventListener('click', () => {
            createCompForm.hidden = true;
            btnNewComp.hidden = false;
            clearError(createCompErr);
        });
        btnSaveComp.addEventListener('click', async () => {
            clearError(createCompErr);
            const name        = document.getElementById('cf_name').value.trim();
            const category    = document.getElementById('cf_category').value.trim();
            const status      = parseInt(document.getElementById('cf_status').value, 10);
            const description = document.getElementById('cf_description').value.trim();

            if (!name) {
                showError(createCompErr, 'Competency name is required.');
                return;
            }

            const payload = {
                name: name,
                category: category || null,
                is_active: status,
                description: description || null,
            };

            btnSaveComp.disabled = true;
            try {
                await api('POST', 'admin/competencies', payload);
                location.reload();
            } catch (err) {
                const msg = err?.message || (err?.errors ? Object.values(err.errors).join(' ') : 'Could not create competency.');
                showError(createCompErr, msg);
            } finally {
                btnSaveComp.disabled = false;
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* 2. Competency Catalog — Edit (HR Only)                             */
    /* ------------------------------------------------------------------ */
    const modalEditComp     = document.getElementById('modalEditCompetency');
    const btnUpdateComp     = document.getElementById('btnUpdateCompetency');
    const editCompErr       = document.getElementById('editCompetencyError');

    document.querySelectorAll('.btn-edit-comp').forEach(btn => {
        btn.addEventListener('click', () => {
            clearError(editCompErr);
            document.getElementById('ecf_id').value          = btn.dataset.id;
            document.getElementById('ecf_name').value        = btn.dataset.name || '';
            document.getElementById('ecf_category').value    = btn.dataset.category || '';
            document.getElementById('ecf_description').value = btn.dataset.description || '';
            document.getElementById('ecf_status').value      = btn.dataset.active === '1' ? '1' : '0';
            openModal(modalEditComp);
        });
    });

    if (btnUpdateComp) {
        btnUpdateComp.addEventListener('click', async () => {
            clearError(editCompErr);
            const id          = parseInt(document.getElementById('ecf_id').value, 10);
            const name        = document.getElementById('ecf_name').value.trim();
            const category    = document.getElementById('cf_category') ? document.getElementById('ecf_category').value.trim() : document.getElementById('ecf_category').value.trim();
            const status      = parseInt(document.getElementById('ecf_status').value, 10);
            const description = document.getElementById('ecf_description').value.trim();

            if (!id || !name) {
                showError(editCompErr, 'Competency name cannot be empty.');
                return;
            }

            const payload = {
                name: name,
                category: category || null,
                is_active: status,
                description: description || null,
            };

            btnUpdateComp.disabled = true;
            try {
                await api('PATCH', `admin/competencies/${id}`, payload);
                location.reload();
            } catch (err) {
                const msg = err?.message || (err?.errors ? Object.values(err.errors).join(' ') : 'Could not update competency.');
                showError(editCompErr, msg);
            } finally {
                btnUpdateComp.disabled = false;
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* 3. Competency Catalog — Archive & Restore (HR Only)                */
    /* ------------------------------------------------------------------ */
    document.querySelectorAll('.btn-archive-comp').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id   = parseInt(btn.dataset.id, 10);
            const name = btn.dataset.name || 'this competency';
            if (!confirm(`Are you sure you want to archive "${name}"?\nExisting employee assignments will be preserved, but no new assignments can be made.`)) {
                return;
            }
            btn.disabled = true;
            try {
                await api('DELETE', `admin/competencies/${id}`);
                location.reload();
            } catch (err) {
                alert(err?.message || 'Could not archive competency.');
                btn.disabled = false;
            }
        });
    });

    document.querySelectorAll('.btn-restore-comp').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id   = parseInt(btn.dataset.id, 10);
            const name = btn.dataset.name || 'this competency';
            if (!confirm(`Restore "${name}" to active status?`)) {
                return;
            }
            btn.disabled = true;
            try {
                await api('PATCH', `admin/competencies/${id}`, { is_active: 1 });
                location.reload();
            } catch (err) {
                alert(err?.message || 'Could not restore competency.');
                btn.disabled = false;
            }
        });
    });

    /* ------------------------------------------------------------------ */
    /* 4. Competency Catalog — View (Managers)                            */
    /* ------------------------------------------------------------------ */
    const modalViewComp = document.getElementById('modalViewCompetency');
    document.querySelectorAll('.btn-view-comp').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('vcf_name').textContent        = btn.dataset.name || '—';
            document.getElementById('vcf_category').textContent    = btn.dataset.category || '—';
            document.getElementById('vcf_status').innerHTML        = btn.dataset.active === '1'
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-secondary">Archived</span>';
            document.getElementById('vcf_count').textContent       = btn.dataset.count || '0';
            document.getElementById('vcf_description').textContent = btn.dataset.description || 'No description provided.';
            openModal(modalViewComp);
        });
    });

    /* ------------------------------------------------------------------ */
    /* 5. Employee Assignments — Assign (HR Only)                         */
    /* ------------------------------------------------------------------ */
    const btnNewAsg     = document.getElementById('btnNewAssignment');
    const createAsgForm = document.getElementById('assignCompetencyForm');
    const btnCancelAsg  = document.getElementById('btnCancelAssignment');
    const btnSaveAsg    = document.getElementById('btnSaveAssignment');
    const createAsgErr  = document.getElementById('assignCompetencyError');

    if (btnNewAsg && createAsgForm) {
        btnNewAsg.addEventListener('click', () => {
            createAsgForm.hidden = false;
            btnNewAsg.hidden = true;
        });
        btnCancelAsg.addEventListener('click', () => {
            createAsgForm.hidden = true;
            btnNewAsg.hidden = false;
            clearError(createAsgErr);
        });
        btnSaveAsg.addEventListener('click', async () => {
            clearError(createAsgErr);
            const employeeId   = parseInt(document.getElementById('af_employee').value, 10);
            const competencyId = parseInt(document.getElementById('af_competency').value, 10);
            const required     = parseInt(document.getElementById('af_required').value, 10);
            const currentVal   = document.getElementById('af_current').value;
            const current      = currentVal !== '' ? parseInt(currentVal, 10) : null;
            const status       = document.getElementById('af_status').value;
            const notes        = document.getElementById('af_notes').value.trim();

            if (!employeeId || !competencyId || !required) {
                showError(createAsgErr, 'Employee, Competency, and Required Target Level are required.');
                return;
            }

            const payload = {
                employee_id: employeeId,
                competency_id: competencyId,
                required_level: required,
                current_level: current,
                status: status,
                notes: notes || null,
            };

            btnSaveAsg.disabled = true;
            try {
                await api('POST', 'admin/competencies/employees', payload);
                location.reload();
            } catch (err) {
                const msg = err?.message || (err?.errors ? Object.values(err.errors).join(' ') : 'Could not assign competency.');
                showError(createAsgErr, msg);
            } finally {
                btnSaveAsg.disabled = false;
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* 6. Employee Assignments — Evaluate / Edit (HR & Manager)           */
    /* ------------------------------------------------------------------ */
    const modalEditAsg   = document.getElementById('modalEditAssignment');
    const btnUpdateAsg   = document.getElementById('btnUpdateAssignment');
    const editAsgErr     = document.getElementById('editAssignmentError');

    document.querySelectorAll('.btn-edit-assignment').forEach(btn => {
        btn.addEventListener('click', () => {
            clearError(editAsgErr);
            document.getElementById('eaf_id').value        = btn.dataset.id;
            document.getElementById('eaf_emp_name').textContent = btn.dataset.empName || '—';
            document.getElementById('eaf_comp_name').textContent = btn.dataset.compName || '—';

            if (IS_HR) {
                const reqSelect = document.getElementById('eaf_required');
                if (reqSelect) reqSelect.value = btn.dataset.required || '3';
            } else {
                const reqDisplay = document.getElementById('eaf_required_display');
                const lvl = parseInt(btn.dataset.required, 10);
                if (reqDisplay) {
                    const lbl = RATING_SCALE[lvl] || '';
                    reqDisplay.innerHTML = `<span class="badge badge-info">${lvl} · ${lbl}</span>`;
                }
            }

            document.getElementById('eaf_current').value = btn.dataset.current || '';
            document.getElementById('eaf_status').value  = btn.dataset.status || 'active';
            document.getElementById('eaf_notes').value   = btn.dataset.notes || '';

            const evalBlock = document.getElementById('eaf_last_eval');
            if (btn.dataset.evaluator) {
                document.getElementById('eaf_eval_by').textContent = btn.dataset.evaluator;
                document.getElementById('eaf_eval_at').textContent = btn.dataset.evaluatedAt || '';
                evalBlock.hidden = false;
            } else {
                evalBlock.hidden = true;
            }

            openModal(modalEditAsg);
        });
    });

    if (btnUpdateAsg) {
        btnUpdateAsg.addEventListener('click', async () => {
            clearError(editAsgErr);
            const id         = parseInt(document.getElementById('eaf_id').value, 10);
            const currentVal = document.getElementById('eaf_current').value;
            const current    = currentVal !== '' ? parseInt(currentVal, 10) : null;
            const status     = document.getElementById('eaf_status').value;
            const notes      = document.getElementById('eaf_notes').value.trim();

            if (!id) return;

            const payload = {
                current_level: current,
                status: status,
                notes: notes || null,
            };

            if (IS_HR) {
                const reqSelect = document.getElementById('eaf_required');
                if (reqSelect) {
                    payload.required_level = parseInt(reqSelect.value, 10);
                }
            }

            btnUpdateAsg.disabled = true;
            try {
                await api('PATCH', `admin/competencies/employees/${id}`, payload);
                location.reload();
            } catch (err) {
                const msg = err?.message || (err?.errors ? Object.values(err.errors).join(' ') : 'Could not save changes.');
                showError(editAsgErr, msg);
            } finally {
                btnUpdateAsg.disabled = false;
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* 7. Employee Assignments — Delete / Remove (HR Only)                */
    /* ------------------------------------------------------------------ */
    document.querySelectorAll('.btn-delete-assignment').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id       = parseInt(btn.dataset.id, 10);
            const empName  = btn.dataset.empName || 'the employee';
            const compName = btn.dataset.compName || 'this competency';
            if (!confirm(`Are you sure you want to remove the competency "${compName}" from ${empName}?\nThis action cannot be undone.`)) {
                return;
            }
            btn.disabled = true;
            try {
                await api('DELETE', `admin/competencies/employees/${id}`);
                location.reload();
            } catch (err) {
                alert(err?.message || 'Could not remove competency assignment.');
                btn.disabled = false;
            }
        });
    });

})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
