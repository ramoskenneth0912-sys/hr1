<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
requireNotApplicant();

$currentUser = getCurrentUser();
$isManager = isManager();
$managerEmployeeId = (int) ($currentUser['employee_id'] ?? 0);
$pageTitle = 'Employee Recognition';
$currentModule = 'recognition';

function recognitionManagerScope(int $managerEmployeeId, int $employeeId): bool
{
    if ($managerEmployeeId <= 0) {
        return false;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM employees WHERE id = ? AND manager_id = ?');
    $stmt->execute([$employeeId, $managerEmployeeId]);
    return (int) $stmt->fetchColumn() > 0;
}

function recognitionEmployees(bool $isManager, int $managerEmployeeId): array
{
    $sql = 'SELECT id, employee_no, first_name, last_name FROM employees WHERE status != \'terminated\'';
    $params = [];
    if ($isManager) {
        $sql .= ' AND manager_id = ?';
        $params[] = $managerEmployeeId;
    }
    $sql .= ' ORDER BY last_name, first_name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function recognitionInput(string $field, int $max, bool $required = true): string
{
    $value = trim((string) ($_POST[$field] ?? ''));
    if ($required && $value === '') {
        throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' is required.');
    }
    if (mb_strlen($value) > $max) {
        throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' is too long.');
    }
    return $value;
}

$employees = recognitionEmployees($isManager, $managerEmployeeId);
$employeeIds = array_map(static fn(array $row): int => (int) $row['id'], $employees);
$editingId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editingId > 0) {
    $stmt = db()->prepare(
        'SELECT * FROM employee_recognitions WHERE id = ?'
        . ($isManager ? ' AND recipient_employee_id IN (SELECT id FROM employees WHERE manager_id = ?)' : '')
    );
    $params = [$editingId];
    if ($isManager) {
        $params[] = $managerEmployeeId;
    }
    $stmt->execute($params);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('danger', 'Recognition record not found or outside your management scope.');
        redirect(BASE_URL . '/modules/hcm/recognition.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    $recordId = (int) ($_POST['recognition_id'] ?? 0);
    try {
        if ($action === 'save') {
            $recipientId = (int) ($_POST['recipient_employee_id'] ?? 0);
            if ($recordId > 0) {
                $scopeStmt = db()->prepare(
                    'SELECT recipient_employee_id FROM employee_recognitions WHERE id = ?'
                    . ($isManager ? ' AND recipient_employee_id IN (SELECT id FROM employees WHERE manager_id = ?)' : '')
                );
                $scopeParams = [$recordId];
                if ($isManager) {
                    $scopeParams[] = $managerEmployeeId;
                }
                $scopeStmt->execute($scopeParams);
                $existingRecipient = $scopeStmt->fetchColumn();
                if ($existingRecipient === false) {
                    throw new InvalidArgumentException('Recognition record not found or outside your management scope.');
                }
                $recipientId = (int) $existingRecipient;
            } elseif (!in_array($recipientId, $employeeIds, true)) {
                throw new InvalidArgumentException('Select an employee within your authorized scope.');
            }
            $category = recognitionInput('category', 80);
            $title = recognitionInput('title', 160);
            $message = recognitionInput('message', 4000);
            $date = recognitionInput('recognition_date', 10);
            $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Recognition date must use YYYY-MM-DD format.');
            }
            $status = strtolower(trim((string) ($_POST['status'] ?? 'draft')));
            if (!in_array($status, ['draft', 'published'], true)) {
                throw new InvalidArgumentException('Invalid recognition status.');
            }
            if ($recordId > 0) {
                $stmt = db()->prepare(
                    'UPDATE employee_recognitions
                     SET category = ?, title = ?, message = ?, recognition_date = ?, status = ?
                     WHERE id = ?'
                );
                $stmt->execute([$category, $title, $message, $date, $status, $recordId]);
                flash('success', 'Recognition record updated.');
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO employee_recognitions
                     (recipient_employee_id, issuer_user_id, category, title, message, recognition_date, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$recipientId, (int) $_SESSION['user_id'], $category, $title, $message, $date, $status]);
                flash('success', 'Recognition record created.');
            }
        } elseif (in_array($action, ['publish', 'archive'], true)) {
            $targetStatus = $action === 'publish' ? 'published' : 'archived';
            $stmt = db()->prepare(
                'UPDATE employee_recognitions SET status = ? WHERE id = ?'
                . ($isManager ? ' AND recipient_employee_id IN (SELECT id FROM employees WHERE manager_id = ?)' : '')
            );
            $params = [$targetStatus, $recordId];
            if ($isManager) {
                $params[] = $managerEmployeeId;
            }
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                throw new InvalidArgumentException('Recognition record not found or outside your management scope.');
            }
            flash('success', 'Recognition record ' . $targetStatus . '.');
        } else {
            throw new InvalidArgumentException('Invalid recognition action.');
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Recognition management failed: ' . $e->getMessage());
        flash('danger', 'The recognition record could not be saved.');
    }
    redirect(BASE_URL . '/modules/hcm/recognition.php');
}

$where = [];
$params = [];
if ($isManager) {
    $where[] = 're.manager_id = ?';
    $params[] = $managerEmployeeId;
}
$statusFilter = trim((string) ($_GET['status'] ?? ''));
if (in_array($statusFilter, ['draft', 'published', 'archived'], true)) {
    $where[] = 'r.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$stmt = db()->prepare(
    'SELECT r.*, re.employee_no, re.first_name, re.last_name,
            u.username AS issuer_username, ie.first_name AS issuer_first_name,
            ie.last_name AS issuer_last_name
     FROM employee_recognitions r
     INNER JOIN employees re ON re.id = r.recipient_employee_id
     INNER JOIN users u ON u.id = r.issuer_user_id
     LEFT JOIN employees ie ON ie.id = u.employee_id'
    . $whereSql . ' ORDER BY r.recognition_date DESC, r.id DESC'
);
$stmt->execute($params);
$records = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
$form = $editing ?: [
    'recipient_employee_id' => '',
    'category' => '',
    'title' => '',
    'message' => '',
    'recognition_date' => date('Y-m-d'),
    'status' => 'draft',
];
?>
<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Employee Recognition</h1>
        <p class="page-subtitle">Create and manage employee recognition records</p>
    </div>
    <?php if ($editing): ?><a href="recognition.php" class="btn btn-outline">Cancel Edit</a><?php endif; ?>
</div>

<section class="panel fade-in-up">
    <h2><?= $editing ? 'Edit Recognition' : 'Create Recognition' ?></h2>
    <form method="post" class="form-grid" style="margin-top:1rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="recognition_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <div class="form-group">
            <label for="recipient_employee_id">Employee</label>
            <select id="recipient_employee_id" name="recipient_employee_id" required <?= $editing ? 'disabled' : '' ?> data-emp-search>
                <option value="">Select employee</option>
                <?php foreach ($employees as $employee): ?>
                <option value="<?= (int) $employee['id'] ?>" <?= (int) $form['recipient_employee_id'] === (int) $employee['id'] ? 'selected' : '' ?> data-emp-no="<?= e($employee['employee_no']) ?>">
                    <?= e($employee['employee_no'] . ' — ' . $employee['first_name'] . ' ' . $employee['last_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($editing): ?><input type="hidden" name="recipient_employee_id" value="<?= (int) $form['recipient_employee_id'] ?>"><?php endif; ?>
        </div>
        <div class="form-group">
            <label for="category">Category</label>
            <select id="category" name="category" required>
                <option value="">Select category</option>
                <?php foreach ([
                    'Outstanding Performance',
                    'Teamwork',
                    'Leadership',
                    'Customer Service',
                    'Innovation',
                    'Reliability',
                    'Other',
                ] as $categoryOption): ?>
                <option value="<?= e($categoryOption) ?>" <?= $form['category'] === $categoryOption ? 'selected' : '' ?>>
                    <?= e($categoryOption) ?>
                </option>
                <?php endforeach; ?>
                <?php if ($form['category'] !== '' && !in_array($form['category'], [
                    'Outstanding Performance',
                    'Teamwork',
                    'Leadership',
                    'Customer Service',
                    'Innovation',
                    'Reliability',
                    'Other',
                ], true)): ?>
                <option value="<?= e($form['category']) ?>" selected><?= e($form['category']) ?> (Existing)</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="title">Title</label>
            <input id="title" name="title" maxlength="160" value="<?= e($form['title']) ?>" required>
        </div>
        <div class="form-group">
            <label for="recognition_date">Recognition Date</label>
            <input id="recognition_date" type="date" name="recognition_date" value="<?= e($form['recognition_date']) ?>" required>
        </div>
        <div class="form-group form-group-full">
            <label for="message">Message</label>
            <textarea id="message" name="message" maxlength="4000" rows="4" required><?= e($form['message']) ?></textarea>
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="draft" <?= $form['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="published" <?= $form['status'] === 'published' ? 'selected' : '' ?>>Published</option>
            </select>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Save Recognition' ?></button>
        </div>
    </form>
</section>

<section class="panel fade-in-up" style="margin-top:1rem;">
    <div class="page-header" style="margin-bottom:1rem;">
        <div><h2>Recognition History</h2><p class="page-subtitle">Published, draft, and archived records within your authorized scope</p></div>
        <form method="get" class="inline-form filter-toolbar">
            <select name="status" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
            </select>
            <button class="btn btn-outline btn-sm" type="submit">Filter</button>
        </form>
    </div>
    <div class="table-wrap">
    <table class="data-table">
        <thead><tr><th>Recipient</th><th>Title</th><th>Category</th><th>Date</th><th>Given By</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$records): ?>
            <tr><td colspan="7" class="empty">No recognition records found. Create a recognition record to begin the history.</td></tr>
        <?php else: foreach ($records as $record): ?>
            <?php $issuer = trim(($record['issuer_first_name'] ?? '') . ' ' . ($record['issuer_last_name'] ?? '')) ?: $record['issuer_username']; ?>
            <tr>
                <td><?= e($record['employee_no'] . ' — ' . $record['first_name'] . ' ' . $record['last_name']) ?></td>
                <td><?= e($record['title']) ?></td>
                <td><?= e($record['category']) ?></td>
                <td><?= formatDate($record['recognition_date']) ?></td>
                <td><?= e($issuer) ?></td>
                <td><?= statusBadge($record['status']) ?></td>
                <td class="actions">
                    <a class="btn btn-sm btn-outline" href="?edit=<?= (int) $record['id'] ?>">Edit</a>
                    <?php if ($record['status'] === 'draft'): ?>
                    <form method="post" class="inline-form" style="display:inline;margin:0;">
                        <?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="recognition_id" value="<?= (int) $record['id'] ?>">
                        <button class="btn btn-sm" type="submit">Publish</button>
                    </form>
                    <?php elseif ($record['status'] === 'published'): ?>
                    <form method="post" class="inline-form" style="display:inline;margin:0;">
                        <?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="recognition_id" value="<?= (int) $record['id'] ?>">
                        <button class="btn btn-sm btn-outline" type="submit">Archive</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
