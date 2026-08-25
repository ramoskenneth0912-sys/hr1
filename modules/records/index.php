<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_document') {
        db()->prepare(
            'INSERT INTO employee_documents (employee_id, document_type, document_name, issue_date, expiry_date, notes)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            (int) $_POST['employee_id'],
            $_POST['document_type'],
            trim($_POST['document_name']),
            $_POST['issue_date'] ?: null,
            $_POST['expiry_date'] ?: null,
            trim($_POST['notes'] ?? ''),
        ]);
        flash('success', 'Document record added.');
        redirect(BASE_URL . '/modules/records/index.php?employee_id=' . (int) $_POST['employee_id']);
    }

    if ($action === 'add_history') {
        db()->prepare(
            'INSERT INTO employment_history (employee_id, event_type, event_date, description, recorded_by)
             VALUES (?,?,?,?,?)'
        )->execute([
            (int) $_POST['employee_id'],
            $_POST['event_type'],
            $_POST['event_date'],
            trim($_POST['description']),
            trim($_POST['recorded_by'] ?? 'HR Admin'),
        ]);
        flash('success', 'Employment history recorded.');
        redirect(BASE_URL . '/modules/records/index.php?employee_id=' . (int) $_POST['employee_id']);
    }
}

$pageTitle = 'Employee Records Management';
$currentModule = 'records';
require_once __DIR__ . '/../../includes/header.php';

$employees = getEmployees(false);
$selectedEmployeeId = (int) ($_GET['employee_id'] ?? ($employees[0]['id'] ?? 0));

$documents = [];
$history = [];

if ($selectedEmployeeId) {
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
}
?>

<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Employee Records Management</h1>
        <p class="page-subtitle">Module 6 — Documents and employment history</p>
    </div>
</div>

<section class="panel fade-in-up" style="animation-delay:.1s">
    <form method="get" class="inline-form">
        <label for="employee_id">Select Employee:</label>
        <select id="employee_id" name="employee_id" onchange="this.form.submit()">
            <?php foreach ($employees as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= $selectedEmployeeId === (int) $emp['id'] ? 'selected' : '' ?>>
                <?= e($emp['employee_no'] . ' — ' . $emp['first_name'] . ' ' . $emp['last_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</section>

<div class="two-col">
    <section class="panel fade-in-up" style="animation-delay:.2s">
        <h2>Add Document Record</h2>
        <form method="post" class="form-panel compact-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_document">
            <input type="hidden" name="employee_id" value="<?= $selectedEmployeeId ?>">
            <div class="form-group">
                <label for="document_type">Document Type</label>
                <select id="document_type" name="document_type" required>
                    <?php foreach (['contract','id','certificate','evaluation','disciplinary','other'] as $t): ?>
                    <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="document_name">Document Name *</label>
                <input type="text" id="document_name" name="document_name" required>
            </div>
            <div class="form-group">
                <label for="issue_date">Issue Date</label>
                <input type="date" id="issue_date" name="issue_date">
            </div>
            <div class="form-group">
                <label for="expiry_date">Expiry Date</label>
                <input type="date" id="expiry_date" name="expiry_date">
            </div>
            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Add Document</button>
        </form>
    </section>

    <section class="panel fade-in-up" style="animation-delay:.3s">
        <h2>Add Employment Event</h2>
        <form method="post" class="form-panel compact-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_history">
            <input type="hidden" name="employee_id" value="<?= $selectedEmployeeId ?>">
            <div class="form-group">
                <label for="event_type">Event Type</label>
                <select id="event_type" name="event_type" required>
                    <?php foreach (['hire','promotion','transfer','salary_change','disciplinary','termination','resignation'] as $t): ?>
                    <option value="<?= $t ?>"><?= ucfirst(str_replace('_', ' ', $t)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="event_date">Event Date *</label>
                <input type="date" id="event_date" name="event_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label for="description">Description *</label>
                <textarea id="description" name="description" rows="3" required></textarea>
            </div>
            <div class="form-group">
                <label for="recorded_by">Recorded By</label>
                <input type="text" id="recorded_by" name="recorded_by" value="HR Admin">
            </div>
            <button type="submit" class="btn btn-primary">Record Event</button>
        </form>
    </section>
</div>

<section class="panel fade-in-up" style="animation-delay:.4s">
    <h2>Documents</h2>
    <table class="data-table">
        <thead>
            <tr><th>Type</th><th>Name</th><th>Issue Date</th><th>Expiry</th><th>Notes</th></tr>
        </thead>
        <tbody>
            <?php if (empty($documents)): ?>
            <tr><td colspan="5" class="empty">No documents on file.</td></tr>
            <?php else: foreach ($documents as $doc): ?>
            <tr>
                <td><?= e(ucfirst($doc['document_type'])) ?></td>
                <td><?= e($doc['document_name']) ?></td>
                <td><?= formatDate($doc['issue_date']) ?></td>
                <td><?= formatDate($doc['expiry_date']) ?></td>
                <td><?= e($doc['notes'] ?: '—') ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<section class="panel fade-in-up" style="animation-delay:.5s">
    <h2>Employment History</h2>
    <table class="data-table">
        <thead>
            <tr><th>Date</th><th>Event</th><th>Description</th><th>Recorded By</th></tr>
        </thead>
        <tbody>
            <?php if (empty($history)): ?>
            <tr><td colspan="4" class="empty">No history records.</td></tr>
            <?php else: foreach ($history as $h): ?>
            <tr>
                <td><?= formatDate($h['event_date']) ?></td>
                <td><?= e(ucfirst(str_replace('_', ' ', $h['event_type']))) ?></td>
                <td><?= e($h['description']) ?></td>
                <td><?= e($h['recorded_by'] ?? '—') ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
