<?php
/**
 * /api/v1/employee/goals — Employee Self-Service "My Goals".
 *
 *   GET    employee/goals         → index   (list the caller's own goals)
 *   PATCH  employee/goals/{id}    → update  (update own goal progress/status/notes)
 *
 * Ownership is ALWAYS resolved from the authenticated user's employee record
 * (session/Bearer), never from client-supplied identifiers. Employees may
 * only touch their own goals, may only set a whitelist of statuses, and may
 * never modify finalized (completed/cancelled) goals. 'overdue' is derived at
 * read time and can never be set directly.
 */

declare(strict_types=1);

class GoalsController
{
    /** Statuses stored in employee_goals (overdue is computed at read time). */
    private const STORED_STATUSES = ['not_started', 'in_progress', 'completed', 'cancelled'];

    /** Statuses an employee is permitted to set on their own goal. */
    private const EMPLOYEE_SETTABLE_STATUSES = ['not_started', 'in_progress', 'completed'];

    private const MAX_NOTES_LENGTH = 3000;
    private const MAX_TITLE_LENGTH = 160;
    private const MAX_DESCRIPTION_LENGTH = 4000;
    private const MAX_CATEGORY_LENGTH = 60;
    private const MAX_TARGET_LENGTH = 255;
    private const ADMIN_STATUSES = ['not_started', 'in_progress', 'completed', 'cancelled'];
    private const PRIORITIES = ['low', 'medium', 'high'];

    /**
     * Resolve the authenticated user's employee_id from the existing session/
     * Bearer auth context. Returns nothing (exits) on failure.
     */
    private static function currentEmployeeId(): int
    {
        $user = Auth::requireAuth();

        if (($user['role'] ?? '') !== 'employee') {
            Response::forbidden('My Goals is available to employees only.');
        }

        $stmt = db()->prepare('SELECT employee_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $user['id']]);
        $employeeId = (int) ($stmt->fetchColumn() ?: 0);

        if ($employeeId <= 0) {
            Response::forbidden('No employee record is linked to your account.');
        }

        return $employeeId;
    }

    /** Fetch one goal, scoped to the employee (null when absent or not owned). */
    private static function row(int $employeeId, int $goalId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM employee_goals WHERE id = :id AND employee_id = :eid LIMIT 1');
        $stmt->execute([':id' => $goalId, ':eid' => $employeeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Read goals for one employee, with overdue derived at read time. */
    private static function read(int $employeeId): array
    {
        $stmt = db()->prepare(
            'SELECT g.*,
                    CASE
                        WHEN g.status IN (\'completed\', \'cancelled\') THEN g.status
                        WHEN g.due_date IS NOT NULL AND g.due_date < CURDATE() THEN \'overdue\'
                        ELSE g.status
                    END AS display_status
             FROM employee_goals g
             WHERE g.employee_id = :eid
             ORDER BY (g.due_date IS NULL) ASC, g.due_date ASC, g.id DESC'
        );
        $stmt->execute([':eid' => $employeeId]);
        return $stmt->fetchAll();
    }

    /** Public JSON shape for one goal. */
    private static function shape(array $g): array
    {
        return [
            'id'          => (int) $g['id'],
            'title'       => $g['title'],
            'description' => $g['description'],
            'target'      => $g['target'],
            'category'    => $g['category'],
            'start_date'  => $g['start_date'],
            'due_date'    => $g['due_date'],
            'progress'    => (int) $g['progress'],
            'priority'    => $g['priority'],
            'status'      => $g['display_status'] ?? $g['status'],
            'notes'       => $g['notes'],
            'created_at'  => $g['created_at'],
            'updated_at'  => $g['updated_at'],
            'editable'    => !in_array($g['status'], ['completed', 'cancelled'], true),
        ];
    }

    /** GET employee/goals — list the caller's own goals. */
    public static function index(): never
    {
        $employeeId = self::currentEmployeeId();
        $rows = self::read($employeeId);
        $items = array_map([self::class, 'shape'], $rows);
        Response::list($items, 'Goals retrieved successfully.', 1, count($items), count($items));
    }

    /** PATCH employee/goals/{id} — update progress/status/notes on own goal. */
    public static function update(int $id, bool $isPatch): never
    {
        $employeeId = self::currentEmployeeId();

        $goal = self::row($employeeId, $id);
        if (!$goal) {
            // 404 for both "does not exist" and "not yours" — never leaks
            // whether another employee's goal exists.
            Response::notFound('Goal not found.');
        }

        if (in_array($goal['status'], ['completed', 'cancelled'], true)) {
            Response::validation(
                ['goal' => 'This goal is finalized (' . str_replace('_', ' ', $goal['status']) . ') and can no longer be modified.'],
                'Goal is finalized and can no longer be modified.'
            );
        }

        $body = api_body();

        // ---- progress: integer 0-100 only --------------------------------
        $progress = null;
        if (array_key_exists('progress', $body)) {
            $v = $body['progress'];
            if (is_int($v)) {
                $progress = $v;
            } elseif (is_string($v) && preg_match('/^\d+$/', $v)) {
                $progress = (int) $v;
            } else {
                Response::validation(
                    ['progress' => 'Progress must be an integer between 0 and 100.'],
                    'Validation failed.'
                );
            }
            if ($progress < 0 || $progress > 100) {
                Response::validation(
                    ['progress' => 'Progress must be between 0 and 100.'],
                    'Validation failed.'
                );
            }
        }

        // ---- status: employee whitelist only -----------------------------
        $status = null;
        if (array_key_exists('status', $body)) {
            $s = strtolower(trim((string) $body['status']));
            if (!in_array($s, self::EMPLOYEE_SETTABLE_STATUSES, true)) {
                Response::validation(
                    ['status' => 'Status updates are limited to: not_started, in_progress, completed. (Overdue is calculated by the system.)'],
                    'Validation failed.'
                );
            }
            $status = $s;
        }

        // ---- notes: employee progress notes ------------------------------
        $notes = null;
        if (array_key_exists('notes', $body)) {
            if (!is_string($body['notes'])) {
                Response::validation(['notes' => 'Notes must be text.'], 'Validation failed.');
            }
            $notes = trim($body['notes']);
            if (mb_strlen($notes) > self::MAX_NOTES_LENGTH) {
                Response::validation(
                    ['notes' => 'Notes may not exceed 3000 characters.'],
                    'Validation failed.'
                );
            }
        }

        if ($progress === null && $status === null && $notes === null) {
            Response::validation(
                ['progress' => 'Provide at least one field to update: progress, status, or notes.'],
                'Nothing to update.'
            );
        }

        $sets = [];
        $params = [':id' => $id, ':eid' => $employeeId];
        if ($progress !== null) {
            $sets[] = 'progress = :progress';
            $params[':progress'] = $progress;
        }
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params[':status'] = $status;
        }
        if ($notes !== null) {
            $sets[] = 'notes = :notes';
            $params[':notes'] = $notes;
        }

        $stmt = db()->prepare(
            'UPDATE employee_goals SET ' . implode(', ', $sets)
            . ' WHERE id = :id AND employee_id = :eid'
        );
        $stmt->execute($params);

        $fresh = self::row($employeeId, $id);
        Response::item(self::shape($fresh), 'Goal updated successfully.');
    }

    private static function adminContext(): array
    {
        $user = Auth::requireAuth();
        if (!in_array((string) ($user['role'] ?? ''), ['hr', 'manager'], true)) {
            Response::forbidden('Goal management requires HR or manager access.');
        }

        $stmt = db()->prepare('SELECT employee_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $user['id']]);
        return [
            'user' => $user,
            'role' => (string) $user['role'],
            'user_id' => (int) $user['id'],
            'employee_id' => (int) ($stmt->fetchColumn() ?: 0),
        ];
    }

    private static function assertAdminEmployeeScope(int $employeeId, array $context): void
    {
        if ($employeeId <= 0) {
            Response::validation(['employee_id' => 'Employee ID must be positive.']);
        }
        $sql = 'SELECT COUNT(*) FROM employees WHERE id = :id AND status != \'terminated\'';
        $params = [':id' => $employeeId];
        if ($context['role'] === 'manager') {
            if ($context['employee_id'] <= 0) {
                Response::forbidden('Your account is not linked to a manager employee record.');
            }
            $sql .= ' AND manager_id = :manager_id';
            $params[':manager_id'] = $context['employee_id'];
        }
        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() <= 0) {
            Response::forbidden('You may only manage goals for employees within your authorized scope.');
        }
    }

    private static function adminGoalScope(int $goalId, array $context): ?array
    {
        $sql = 'SELECT g.*, e.employee_no, e.first_name, e.last_name
                FROM employee_goals g
                INNER JOIN employees e ON e.id = g.employee_id
                WHERE g.id = :id';
        $params = [':id' => $goalId];
        if ($context['role'] === 'manager') {
            $sql .= ' AND e.manager_id = :manager_id';
            $params[':manager_id'] = $context['employee_id'];
        }
        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function adminText(array $body, string $field, int $max, bool $required = false): ?string
    {
        if (!array_key_exists($field, $body)) {
            if ($required) {
                Response::validation([$field => 'This field is required.']);
            }
            return null;
        }
        if (!is_string($body[$field])) {
            Response::validation([$field => 'This field must be text.']);
        }
        $value = trim($body[$field]);
        if ($required && $value === '') {
            Response::validation([$field => 'This field is required.']);
        }
        if (mb_strlen($value) > $max) {
            Response::validation([$field => 'This field may not exceed ' . $max . ' characters.']);
        }
        return $value;
    }

    private static function adminDate(array $body, string $field, bool $required = false): ?string
    {
        $value = self::adminText($body, $field, 10, $required);
        if ($value === null || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            Response::validation([$field => 'Date must use YYYY-MM-DD format.']);
        }
        return $value;
    }

    private static function adminStatus(array $body, string $default = 'not_started'): string
    {
        $value = strtolower(trim((string) ($body['status'] ?? $default)));
        if (!in_array($value, self::ADMIN_STATUSES, true)) {
            Response::validation(['status' => 'Invalid goal status.']);
        }
        return $value;
    }

    private static function adminPriority(array $body, string $default = 'medium'): string
    {
        $value = strtolower(trim((string) ($body['priority'] ?? $default)));
        if (!in_array($value, self::PRIORITIES, true)) {
            Response::validation(['priority' => 'Invalid goal priority.']);
        }
        return $value;
    }

    private static function adminProgress(array $body, int $default = 0): int
    {
        if (!array_key_exists('progress', $body)) {
            return $default;
        }
        $value = filter_var($body['progress'], FILTER_VALIDATE_INT);
        if ($value === false || $value < 0 || $value > 100) {
            Response::validation(['progress' => 'Progress must be an integer between 0 and 100.']);
        }
        return (int) $value;
    }

    private static function adminShape(array $goal): array
    {
        $displayStatus = $goal['status'];
        if (!in_array($displayStatus, ['completed', 'cancelled'], true)
            && $goal['due_date'] !== null && $goal['due_date'] < date('Y-m-d')) {
            $displayStatus = 'overdue';
        }
        return [
            'id' => (int) $goal['id'],
            'employee_id' => (int) $goal['employee_id'],
            'employee_no' => $goal['employee_no'],
            'employee_name' => trim($goal['first_name'] . ' ' . $goal['last_name']),
            'title' => $goal['title'],
            'description' => $goal['description'],
            'category' => $goal['category'],
            'target' => $goal['target'],
            'start_date' => $goal['start_date'],
            'due_date' => $goal['due_date'],
            'progress' => (int) $goal['progress'],
            'status' => $displayStatus,
            'priority' => $goal['priority'],
            'notes' => $goal['notes'],
            'assigned_by' => $goal['assigned_by'] === null ? null : (int) $goal['assigned_by'],
            'created_at' => $goal['created_at'],
            'updated_at' => $goal['updated_at'],
        ];
    }

    public static function adminIndex(): never
    {
        $context = self::adminContext();
        $where = [];
        $params = [];
        if ($context['role'] === 'manager') {
            $where[] = 'e.manager_id = :manager_id';
            $params[':manager_id'] = $context['employee_id'];
        }
        $status = api_query('status');
        if ($status !== null && $status !== '') {
            if (!in_array((string) $status, self::ADMIN_STATUSES, true)) {
                Response::validation(['status' => 'Invalid goal status.']);
            }
            $where[] = 'g.status = :status';
            $params[':status'] = (string) $status;
        }
        $employeeId = api_query('employee_id');
        if ($employeeId !== null && $employeeId !== '') {
            $employeeId = filter_var($employeeId, FILTER_VALIDATE_INT);
            if ($employeeId === false) {
                Response::validation(['employee_id' => 'Employee ID must be an integer.']);
            }
            self::assertAdminEmployeeScope((int) $employeeId, $context);
            $where[] = 'g.employee_id = :employee_id';
            $params[':employee_id'] = (int) $employeeId;
        }
        $sql = 'SELECT g.*, e.employee_no, e.first_name, e.last_name
                FROM employee_goals g INNER JOIN employees e ON e.id = g.employee_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY (g.due_date IS NULL) ASC, g.due_date ASC, g.id DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $items = array_map([self::class, 'adminShape'], $stmt->fetchAll());
        Response::list($items, 'Goals retrieved successfully.', 1, count($items), count($items));
    }

    public static function adminShow(int $id): never
    {
        $goal = self::adminGoalScope($id, self::adminContext());
        if (!$goal) {
            Response::notFound('Goal not found.');
        }
        Response::item(self::adminShape($goal), 'Goal retrieved successfully.');
    }

    public static function adminStore(): never
    {
        $context = self::adminContext();
        $body = api_body();
        $employeeId = filter_var($body['employee_id'] ?? null, FILTER_VALIDATE_INT);
        if ($employeeId === false) {
            Response::validation(['employee_id' => 'Employee ID is required and must be an integer.']);
        }
        self::assertAdminEmployeeScope((int) $employeeId, $context);
        $title = self::adminText($body, 'title', self::MAX_TITLE_LENGTH, true);
        $description = self::adminText($body, 'description', self::MAX_DESCRIPTION_LENGTH);
        $category = self::adminText($body, 'category', self::MAX_CATEGORY_LENGTH);
        $target = self::adminText($body, 'target', self::MAX_TARGET_LENGTH);
        $startDate = self::adminDate($body, 'start_date');
        $dueDate = self::adminDate($body, 'due_date');
        $progress = self::adminProgress($body);
        $status = self::adminStatus($body);
        $priority = self::adminPriority($body);
        $stmt = db()->prepare(
            'INSERT INTO employee_goals
             (employee_id, title, description, category, target, start_date, due_date, progress, status, priority, assigned_by)
             VALUES (:employee_id, :title, :description, :category, :target, :start_date, :due_date, :progress, :status, :priority, :assigned_by)'
        );
        $stmt->execute([
            ':employee_id' => (int) $employeeId, ':title' => $title, ':description' => $description,
            ':category' => $category, ':target' => $target, ':start_date' => $startDate, ':due_date' => $dueDate,
            ':progress' => $progress, ':status' => $status, ':priority' => $priority,
            ':assigned_by' => $context['user_id'],
        ]);
        $goal = self::adminGoalScope((int) db()->lastInsertId(), $context);
        Response::item(self::adminShape($goal), 'Goal created successfully.', 201);
    }

    public static function adminUpdate(int $id): never
    {
        $context = self::adminContext();
        $goal = self::adminGoalScope($id, $context);
        if (!$goal) {
            Response::notFound('Goal not found.');
        }
        $body = api_body();
        $sets = [];
        $params = [':id' => $id];
        foreach ([
            'title' => [self::MAX_TITLE_LENGTH, true],
            'description' => [self::MAX_DESCRIPTION_LENGTH, false],
            'category' => [self::MAX_CATEGORY_LENGTH, false],
            'target' => [self::MAX_TARGET_LENGTH, false],
        ] as $field => [$max, $required]) {
            if (array_key_exists($field, $body)) {
                $sets[] = $field . ' = :' . $field;
                $params[':' . $field] = self::adminText($body, $field, $max, $required);
            }
        }
        if (array_key_exists('start_date', $body)) {
            $sets[] = 'start_date = :start_date';
            $params[':start_date'] = self::adminDate($body, 'start_date');
        }
        if (array_key_exists('due_date', $body)) {
            $sets[] = 'due_date = :due_date';
            $params[':due_date'] = self::adminDate($body, 'due_date');
        }
        if (array_key_exists('progress', $body)) {
            $sets[] = 'progress = :progress';
            $params[':progress'] = self::adminProgress($body, (int) $goal['progress']);
        }
        if (array_key_exists('status', $body)) {
            $sets[] = 'status = :status';
            $params[':status'] = self::adminStatus($body, (string) $goal['status']);
        }
        if (array_key_exists('priority', $body)) {
            $sets[] = 'priority = :priority';
            $params[':priority'] = self::adminPriority($body, (string) $goal['priority']);
        }
        if (array_key_exists('notes', $body)) {
            $sets[] = 'notes = :notes';
            $params[':notes'] = self::adminText($body, 'notes', self::MAX_NOTES_LENGTH);
        }
        if (!$sets) {
            Response::validation(['goal' => 'Provide at least one goal field to update.']);
        }
        $stmt = db()->prepare('UPDATE employee_goals SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
        $fresh = self::adminGoalScope($id, $context);
        Response::item(self::adminShape($fresh), 'Goal updated successfully.');
    }
}