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
}