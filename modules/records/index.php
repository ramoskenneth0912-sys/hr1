<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$pageTitle = 'Employee Records';
$currentModule = 'records';
require_once __DIR__ . '/../../includes/header.php';

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$employees = getEmployees(false);

usort($employees, static function ($a, $b): int {
    $hireCmp = strcmp((string) ($b['hire_date'] ?? ''), (string) ($a['hire_date'] ?? ''));
    if ($hireCmp !== 0) {
        return $hireCmp;
    }
    return strcmp((string) ($a['employee_no'] ?? ''), (string) ($b['employee_no'] ?? ''));
});

/*
 * Case-insensitive search across employee number, name (first/last),
 * current role (job_title), and department. Applied only to the in-memory
 * list for display — employee records in the database are never modified.
 */
if ($q !== '') {
    $employees = array_values(array_filter($employees, static function ($emp) use ($q) {
        $fullName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
        foreach (['employee_no', 'first_name', 'last_name', 'job_title', 'department_name'] as $field) {
            if (stripos((string) ($emp[$field] ?? ''), $q) !== false) {
                return true;
            }
        }
        return stripos($fullName, $q) !== false;
    }));
}

/*
 * Resolve the selected employee safely. We never trust a raw employee_id from
 * the URL; the id is only accepted if it matches an actual employee HR/Admin
 * is authorized to view (the whole page is gated by requireHRorManager()).
 * An absent / unknown id simply means "no selection" and shows no records.
 */
$selectedEmployee = null;
if (isset($_GET['employee_id'])) {
    $wanted = (int) $_GET['employee_id'];
    foreach ($employees as $emp) {
        if ((int) $emp['id'] === $wanted) {
            $selectedEmployee = $emp;
            break;
        }
    }
}
$selectedEmployeeId = $selectedEmployee['id'] ?? 0;

$documents = [];
$history = [];

if ($selectedEmployeeId) {
    // Existing document and lifecycle records for the selected employee.
    $stmt = db()->prepare(
        'SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY uploaded_at DESC'
    );
    $stmt->execute([$selectedEmployeeId]);
    $documents = $stmt->fetchAll();

    $stmt = db()->prepare(
        'SELECT * FROM employment_history WHERE employee_id = ? ORDER BY event_date DESC'
    );
    $stmt->execute([$selectedEmployeeId]);
    $history = $stmt->fetchAll();

    // Onboarding assignments for the selected employee (read-only reuse — no
    // duplicate tables, no manual re-entry). Completed onboarding work is
    // reflected here automatically so HR/Admin never re-enters it into Records.
    $stmt = db()->prepare(
        'SELECT eo.task_id, eo.status, eo.completed_date, eo.completed_by, eo.notes,
                ot.task_name, ot.category, ot.sort_order, ot.is_required
         FROM employee_onboarding eo
         JOIN onboarding_tasks ot ON eo.task_id = ot.id
         WHERE eo.employee_id = ?
         ORDER BY ot.sort_order'
    );
    $stmt->execute([$selectedEmployeeId]);
    $onboarding = $stmt->fetchAll();

    // Merge: completed documentation requirements surface as documents,
    // unless a matching document record already exists for the employee.
    $existingDocNames = [];
    foreach ($documents as $d) {
        $existingDocNames[strtolower(trim($d['document_name'] ?? ''))] = true;
    }
    $docTypeLabel = [
        'documentation' => 'Documentation',
        'orientation'   => 'Orientation',
        'training'      => 'Training',
        'equipment'     => 'Equipment',
        'compliance'    => 'Compliance',
    ];
    foreach ($onboarding as $o) {
        if ($o['category'] !== 'documentation' || $o['status'] !== 'completed') {
            continue;
        }
        if (isset($existingDocNames[strtolower(trim($o['task_name']))])) {
            continue; // already on file as an explicit document record
        }
        $documents[] = [
            'doc_from_onboarding' => true,
            'document_name'       => $o['task_name'],
            'document_type'       => $docTypeLabel[$o['category']] ?? ucfirst($o['category']),
            'issue_date'          => $o['completed_date'],
            'expiry_date'         => null,
            'notes'               => $o['notes'] ?? '',
            'doc_status'          => 'Completed',
            'updated_date'        => $o['completed_date'],
        ];
    }

    // Merge: completed onboarding lifecycle milestones surface as employment
    // history events (orientation, training, equipment, compliance), unless an
    // equivalent lifecycle event already exists for the employee.
    $existingHistTypes = [];
    foreach ($history as $h) {
        $existingHistTypes[strtolower(trim($h['description'] ?? ''))] = true;
    }
    $eventTypeLabel = [
        'orientation' => 'Orientation',
        'training'    => 'Training',
        'equipment'   => 'Equipment',
        'compliance'  => 'Compliance',
    ];
    foreach ($onboarding as $o) {
        if ($o['category'] === 'documentation' || $o['status'] !== 'completed') {
            continue;
        }
        $desc = $o['task_name'];
        if (isset($existingHistTypes[strtolower(trim($desc))])) {
            continue; // already recorded as a lifecycle event
        }
        $history[] = [
            'hist_from_onboarding' => true,
            'event_type'           => $eventTypeLabel[$o['category']] ?? ucfirst($o['category']),
            'event_date'           => $o['completed_date'],
            'description'          => $desc,
            'recorded_by'          => $o['completed_by'] ?: 'Onboarding',
        ];
    }
}

/*
 * Normalize a row for display in the Documents table.
 */
function recordDocRow(array $r): array
{
    if (!empty($r['doc_from_onboarding'])) {
        return [
            'name'      => $r['document_name'],
            'type'      => $r['document_type'],
            'date'      => $r['issue_date'],
            'expiry'    => null,
            'notes'     => $r['notes'],
            'status'    => $r['doc_status'],
            'updated'   => $r['updated_date'],
        ];
    }
    return [
        'name'      => $r['document_name'],
        'type'      => ucfirst($r['document_type']),
        'date'      => $r['issue_date'],
        'expiry'    => $r['expiry_date'],
        'notes'     => $r['notes'] ?? '',
        'status'    => 'On File',
        'updated'   => $r['uploaded_at'] ?? null,
    ];
}
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Employee Records</h1>
        <p class="page-subtitle">Documents and employment history</p>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <h2>Select Employee</h2>
    <style>
        .records-search { display: flex; align-items: center; gap: .4rem; }
        .records-search input {
            padding: .5rem .75rem; border: 1px solid var(--border); border-radius: var(--radius-sm);
            font-size: .875rem; font-family: inherit; color: var(--text); background: var(--surface);
            min-width: 240px;
        }
        .records-search input::placeholder { color: var(--muted); }
        .records-search input:focus { outline: none; border-color: var(--purple-light); box-shadow: 0 0 0 3px var(--purple-bg); }
        .records-search .btn { display: inline-flex; align-items: center; justify-content: center; padding: .5rem .7rem; }
        .records-search .btn svg { display: block; }
        .search-results { margin-top: .75rem; }
        .result-list { margin: 0; padding-left: 1.4rem; list-style: none; font-size: .875rem; color: var(--text); }
        .result-list li { margin-bottom: .3rem; }
        .result-num { color: var(--muted); margin-right: .4rem; font-variant-numeric: tabular-nums; }
        .result-list a { color: var(--purple); text-decoration: none; }
        .result-list a:hover { text-decoration: underline; }
    </style>
    <form method="get" class="inline-form">
        <div class="records-search">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search number, name, role, or department..." aria-label="Search employees">
            <button type="submit" class="btn" aria-label="Search employees">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </button>
            <?php if ($q !== ''): ?>
            <a href="<?= BASE_URL ?>/modules/records/index.php" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($q !== ''): ?>
    <div class="search-results">
        <?php if (empty($employees)): ?>
            <p class="empty">No employees found.</p>
        <?php else: ?>
            <ol class="result-list">
            <?php $n = 1; foreach ($employees as $emp): ?>
                <li><span class="result-num"><?= $n ?>.</span>
                    <a href="<?= BASE_URL ?>/modules/records/index.php?employee_id=<?= (int) $emp['id'] ?>&amp;q=<?= urlencode($q) ?>">
                        <?= e($emp['employee_no'] . ' — ' . $emp['first_name'] . ' ' . $emp['last_name']) ?> — <?= e($emp['job_title']) ?> — <?= e($emp['department_name']) ?>
                    </a>
                </li>
            <?php $n++; endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<?php if (!$selectedEmployeeId): ?>
    <section class="panel fade-in-up" style="animation-delay:.2s">
        <p class="empty">Select an employee to view their records.</p>
    </section>
<?php endif; ?>

<?php if ($selectedEmployeeId && $selectedEmployee): ?>
<section class="panel fade-in-up" style="animation-delay:.2s">
    <h2>Employee</h2>
    <p>
        <strong><?= e($selectedEmployee['first_name'] . ' ' . $selectedEmployee['last_name']) ?></strong><br>
        <?= e($selectedEmployee['employee_no']) ?><br>
        <?= e($selectedEmployee['job_title']) ?>
    </p>
</section>

<section class="panel fade-in-up" style="animation-delay:.3s">
    <h2>Documents</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Document Name</th>
                <th>Document Type</th>
                <th>Issue/Submission Date</th>
                <th>Expiry Date</th>
                <th>Status</th>
                <th>Updated</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($documents)): ?>
            <tr><td colspan="7" class="empty">No documents recorded for this employee.</td></tr>
            <?php else: ?>
                <?php foreach ($documents as $r): $d = recordDocRow($r); ?>
                <tr>
                    <td><?= e($d['name']) ?></td>
                    <td><?= e($d['type']) ?></td>
                    <td><?= formatDate($d['date']) ?></td>
                    <td><?= formatDate($d['expiry']) ?></td>
                    <td><?= $d['status'] === 'On File' ? e('On File') : statusBadge(strtolower($d['status'])) ?></td>
                    <td><?= $d['updated'] ? e(date('M d, Y', strtotime($d['updated']))) : '—' ?></td>
                    <td><?= e($d['notes'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel fade-in-up" style="animation-delay:.4s">
    <h2>Employment History</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Event Type</th>
                <th>Event Date</th>
                <th>Description</th>
                <th>Recorded By</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($history)): ?>
            <tr><td colspan="4" class="empty">No employment history recorded for this employee.</td></tr>
            <?php else: foreach ($history as $r): ?>
            <tr>
                <td><?= e(ucfirst(str_replace('_', ' ', $r['event_type']))) ?></td>
                <td><?= formatDate($r['event_date']) ?></td>
                <td><?= e($r['description']) ?></td>
                <td><?= e($r['recorded_by'] ?? '—') ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
