<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

$pageTitle = 'Employee Records';
$currentModule = 'records';
require_once __DIR__ . '/../../includes/header.php';

$employees = getEmployees(false);

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
    <form method="get" class="inline-form">
        <label for="employee_id">Employee:</label>
        <select id="employee_id" name="employee_id" onchange="this.form.submit()">
            <option value="">Select an Employee</option>
            <?php foreach ($employees as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= $selectedEmployeeId === (int) $emp['id'] ? 'selected' : '' ?>>
                <?= e($emp['employee_no'] . ' — ' . $emp['first_name'] . ' ' . $emp['last_name']) ?> — <?= e($emp['job_title']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
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
