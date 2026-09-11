<?php
require_once __DIR__ . '/../../includes/auth.php';
requireHRorManager();
requireNotApplicant();

$currentUser = getCurrentUser();
$isManager = isManager();
$managerEmployeeId = (int) ($currentUser['employee_id'] ?? 0);
$pageTitle = 'Employee Goals';
$currentModule = 'goals';

function goalsEmployees(bool $isManager, int $managerEmployeeId): array
{
    $sql = 'SELECT id, employee_no, first_name, last_name
            FROM employees
            WHERE status != \'terminated\'';
    $params = [];
    if ($isManager) {
        $sql .= ' AND manager_id = ?';
        $params[] = $managerEmployeeId;
    }
    $stmt = db()->prepare($sql . ' ORDER BY last_name, first_name');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function goalsInput(string $field, int $max, bool $required = false): ?string
{
    if (!array_key_exists($field, $_POST)) {
        if ($required) {
            throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' is required.');
        }
        return null;
    }
    $value = trim((string) $_POST[$field]);
    if ($required && $value === '') {
        throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' is required.');
    }
    if (mb_strlen($value) > $max) {
        throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' is too long.');
    }
    return $value;
}

function goalsDate(string $field): ?string
{
    $value = goalsInput($field, 10);
    if ($value === null || $value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' must use YYYY-MM-DD format.');
    }
    return $value;
}

$employees = goalsEmployees($isManager, $managerEmployeeId);
$employeeIds = array_map(static fn(array $row): int => (int) $row['id'], $employees);
$editingId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editingId > 0) {
    $sql = 'SELECT g.* FROM employee_goals g WHERE g.id = ?';
    $params = [$editingId];
    if ($isManager) {
        $sql .= ' AND g.employee_id IN (SELECT id FROM employees WHERE manager_id = ?)';
        $params[] = $managerEmployeeId;
    }
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('danger', 'Goal not found or outside your management scope.');
        redirect(BASE_URL . '/modules/hcm/goals.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    try {
        $goalId = (int) ($_POST['goal_id'] ?? 0);
        if ($action === 'save') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $title = goalsInput('title', 160, true);
            $description = goalsInput('description', 4000);
            $category = goalsInput('category', 60);
            $target = goalsInput('target', 255);
            $startDate = goalsDate('start_date');
            $dueDate = goalsDate('due_date');
            $progress = filter_var($_POST['progress'] ?? 0, FILTER_VALIDATE_INT);
            if ($progress === false || $progress < 0 || $progress > 100) {
                throw new InvalidArgumentException('Progress must be an integer between 0 and 100.');
            }
            $status = strtolower(trim((string) ($_POST['status'] ?? 'not_started')));
            if (!in_array($status, ['not_started', 'in_progress', 'completed', 'cancelled'], true)) {
                throw new InvalidArgumentException('Invalid goal status.');
            }
            $priority = strtolower(trim((string) ($_POST['priority'] ?? 'medium')));
            if (!in_array($priority, ['low', 'medium', 'high'], true)) {
                throw new InvalidArgumentException('Invalid goal priority.');
            }

            if ($goalId > 0) {
                $scopeSql = 'SELECT employee_id FROM employee_goals WHERE id = ?';
                $scopeParams = [$goalId];
                if ($isManager) {
                    $scopeSql .= ' AND employee_id IN (SELECT id FROM employees WHERE manager_id = ?)';
                    $scopeParams[] = $managerEmployeeId;
                }
                $scopeStmt = db()->prepare($scopeSql . ' LIMIT 1');
                $scopeStmt->execute($scopeParams);
                if ($scopeStmt->fetchColumn() === false) {
                    throw new InvalidArgumentException('Goal not found or outside your management scope.');
                }
                $stmt = db()->prepare(
                    'UPDATE employee_goals
                     SET title = ?, description = ?, category = ?, target = ?, start_date = ?,
                         due_date = ?, progress = ?, status = ?, priority = ?
                     WHERE id = ?'
                );
                $stmt->execute([$title, $description, $category, $target, $startDate, $dueDate, $progress, $status, $priority, $goalId]);
                flash('success', 'Goal updated.');
            } else {
                if (!in_array($employeeId, $employeeIds, true)) {
                    throw new InvalidArgumentException('Select an employee within your authorized scope.');
                }
                $stmt = db()->prepare(
                    'INSERT INTO employee_goals
                     (employee_id, title, description, category, target, start_date, due_date, progress, status, priority, assigned_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $employeeId, $title, $description, $category, $target, $startDate, $dueDate,
                    $progress, $status, $priority, (int) $_SESSION['user_id'],
                ]);
                flash('success', 'Goal assigned.');
            }
        } elseif ($action === 'close') {
            if ($goalId <= 0) {
                throw new InvalidArgumentException('Invalid goal.');
            }
            $sql = 'UPDATE employee_goals SET status = \'completed\', progress = 100 WHERE id = ?';
            $params = [$goalId];
            if ($isManager) {
                $sql .= ' AND employee_id IN (SELECT id FROM employees WHERE manager_id = ?)';
                $params[] = $managerEmployeeId;
            }
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                throw new InvalidArgumentException('Goal not found or outside your management scope.');
            }
            flash('success', 'Goal closed.');
        } else {
            throw new InvalidArgumentException('Invalid goal action.');
        }
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Goal management failed: ' . $e->getMessage());
        flash('danger', 'The goal could not be saved.');
    }
    redirect(BASE_URL . '/modules/hcm/goals.php');
}

$where = [];
$params = [];
if ($isManager) {
    $where[] = 'e.manager_id = ?';
    $params[] = $managerEmployeeId;
}
$statusFilter = trim((string) ($_GET['status'] ?? ''));
if (in_array($statusFilter, ['not_started', 'in_progress', 'completed', 'cancelled'], true)) {
    $where[] = 'g.status = ?';
    $params[] = $statusFilter;
}
$employeeFilter = (int) ($_GET['employee_id'] ?? 0);
if ($employeeFilter > 0 && in_array($employeeFilter, $employeeIds, true)) {
    $where[] = 'g.employee_id = ?';
    $params[] = $employeeFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$stmt = db()->prepare(
    'SELECT g.*, e.employee_no, e.first_name, e.last_name
     FROM employee_goals g
     INNER JOIN employees e ON e.id = g.employee_id'
    . $whereSql . ' ORDER BY (g.due_date IS NULL) ASC, g.due_date ASC, g.id DESC'
);
$stmt->execute($params);
$goals = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
$form = $editing ?: [
    'employee_id' => '',
    'title' => '',
    'description' => '',
    'category' => '',
    'target' => '',
    'start_date' => '',
    'due_date' => '',
    'progress' => 0,
    'status' => 'not_started',
    'priority' => 'medium',
];
?>
<div class="page-header fade-in-up">
    <div>
        <h1 class="page-title">Employee Goals</h1>
        <p class="page-subtitle">Assign and monitor official employee goals within your authorized scope</p>
    </div>
    <?php if ($editing): ?><a href="goals.php" class="btn btn-outline">Cancel Edit</a><?php endif; ?>
</div>

<section class="panel fade-in-up">
    <div class="page-header" style="margin-bottom:1rem;">
        <div><h2><?= $editing ? 'Edit Goal' : 'Assign Goal' ?></h2><p class="page-subtitle">Goals remain assigned to the selected employee.</p></div>
    </div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="goal_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <div class="form-group">
            <label for="employee_id">Employee</label>
            <select id="employee_id" name="employee_id" <?= $editing ? 'disabled' : 'required' ?>>
                <option value="">Select employee</option>
                <?php foreach ($employees as $employee): ?>
                <option value="<?= (int) $employee['id'] ?>" <?= (int) $form['employee_id'] === (int) $employee['id'] ? 'selected' : '' ?>>
                    <?= e($employee['employee_no'] . ' — ' . $employee['first_name'] . ' ' . $employee['last_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="title">Goal Title</label>
            <input id="title" name="title" maxlength="160" value="<?= e($form['title']) ?>" required>
        </div>
        <div class="form-group">
            <label for="category">Category</label>
            <input id="category" name="category" maxlength="60" value="<?= e($form['category']) ?>">
        </div>
        <div class="form-group">
            <label for="target">Target / Deliverable</label>
            <input id="target" name="target" maxlength="255" value="<?= e($form['target']) ?>">
        </div>
        <div class="form-group">
            <label for="start_date">Start Date</label>
            <input id="start_date" type="date" name="start_date" value="<?= e($form['start_date']) ?>">
        </div>
        <div class="form-group">
            <label for="due_date">Due Date</label>
            <input id="due_date" type="date" name="due_date" value="<?= e($form['due_date']) ?>">
        </div>
        <div class="form-group">
            <label for="priority">Priority</label>
            <select id="priority" name="priority">
                <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= $form['priority'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (['not_started' => 'Not Started', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= $form['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="progress">Progress (0–100)</label>
            <input id="progress" type="number" min="0" max="100" name="progress" value="<?= (int) $form['progress'] ?>">
        </div>
        <div class="form-group form-group-full">
            <label for="description">Description</label>
            <textarea id="description" name="description" maxlength="4000" rows="4"><?= e($form['description']) ?></textarea>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Assign Goal' ?></button>
        </div>
    </form>
</section>

<section class="panel fade-in-up" style="margin-top:1rem;">
    <div class="page-header" style="margin-bottom:1rem;">
        <div><h2>Goal History</h2><p class="page-subtitle">Monitor assigned goals and current employee progress</p></div>
        <form method="get" class="inline-form">
            <select name="employee_id" aria-label="Filter by employee">
                <option value="0">All employees</option>
                <?php foreach ($employees as $employee): ?>
                <option value="<?= (int) $employee['id'] ?>" <?= $employeeFilter === (int) $employee['id'] ? 'selected' : '' ?>><?= e($employee['employee_no'] . ' — ' . $employee['first_name'] . ' ' . $employee['last_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option value="not_started" <?= $statusFilter === 'not_started' ? 'selected' : '' ?>>Not Started</option>
                <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
            <button class="btn btn-outline btn-sm" type="submit">Filter</button>
        </form>
    </div>
    <table class="data-table">
        <thead><tr><th>Employee</th><th>Goal</th><th>Due Date</th><th>Progress</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$goals): ?>
            <tr><td colspan="7" class="empty">No goals found. Assign a goal to begin tracking progress.</td></tr>
        <?php else: foreach ($goals as $goal): ?>
            <?php
            $displayStatus = $goal['status'];
            if (!in_array($displayStatus, ['completed', 'cancelled'], true) && $goal['due_date'] !== null && $goal['due_date'] < date('Y-m-d')) {
                $displayStatus = 'overdue';
            }
            ?>
            <tr>
                <td><?= e($goal['employee_no'] . ' — ' . $goal['first_name'] . ' ' . $goal['last_name']) ?></td>
                <td><strong><?= e($goal['title']) ?></strong><?php if ($goal['description']): ?><br><small><?= e($goal['description']) ?></small><?php endif; ?></td>
                <td><?= $goal['due_date'] ? formatDate($goal['due_date']) : '—' ?></td>
                <td><?= (int) $goal['progress'] ?>%</td>
                <td><?= e(ucfirst($goal['priority'])) ?></td>
                <td><?= statusBadge($displayStatus) ?></td>
                <td class="actions">
                    <a class="btn btn-sm btn-outline" href="?edit=<?= (int) $goal['id'] ?>">Edit</a>
                    <?php if (!in_array($goal['status'], ['completed', 'cancelled'], true)): ?>
                    <form method="post" class="inline-form" style="display:inline;margin:0;">
                        <?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                        <button class="btn btn-sm" type="submit">Close</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
