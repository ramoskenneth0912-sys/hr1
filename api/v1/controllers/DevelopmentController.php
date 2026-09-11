<?php
/**
 * /api/v1/employee/development — employee-owned development planning.
 *
 * Ownership is always resolved from the authenticated user's linked employee
 * record. Client-supplied employee identifiers are neither accepted nor used.
 */

declare(strict_types=1);

class DevelopmentController
{
    private const OWNER_ROLES = ['employee', 'manager'];
    private const PLAN_STATUSES = ['draft', 'in_progress', 'completed', 'cancelled'];
    private const ACTIVITY_STATUSES = ['not_started', 'in_progress', 'completed', 'cancelled'];
    private const MAX_TITLE = 160;
    private const MAX_FOCUS = 80;
    private const MAX_DESCRIPTION = 4000;
    private const MAX_NOTES = 3000;

    private static function employeeId(): int
    {
        $user = Auth::requireAuth();
        if (!in_array((string) ($user['role'] ?? ''), self::OWNER_ROLES, true)) {
            Response::forbidden('Development planning is available to employees only.');
        }

        $stmt = db()->prepare('SELECT employee_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $user['id']]);
        $employeeId = (int) ($stmt->fetchColumn() ?: 0);
        if ($employeeId <= 0) {
            Response::forbidden('No employee record is linked to your account.');
        }
        return $employeeId;
    }

    private static function plan(int $employeeId, int $planId): ?array
    {
        $stmt = db()->prepare(
            'SELECT * FROM development_plans WHERE id = :id AND employee_id = :employee_id LIMIT 1'
        );
        $stmt->execute([':id' => $planId, ':employee_id' => $employeeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function activity(int $employeeId, int $planId, int $activityId): ?array
    {
        $stmt = db()->prepare(
            'SELECT a.*
             FROM development_activities a
             INNER JOIN development_plans p ON p.id = a.plan_id
             WHERE a.id = :activity_id AND a.plan_id = :plan_id AND p.employee_id = :employee_id
             LIMIT 1'
        );
        $stmt->execute([
            ':activity_id' => $activityId,
            ':plan_id' => $planId,
            ':employee_id' => $employeeId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function dateValue(array $body, string $field): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || trim((string) $body[$field]) === '') {
            return null;
        }
        $value = trim((string) $body[$field]);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            Response::validation([$field => 'Date must use YYYY-MM-DD format.']);
        }
        return $value;
    }

    private static function text(array $body, string $field, int $max, bool $required = false): ?string
    {
        $value = array_key_exists($field, $body) ? $body[$field] : null;
        if ($value === null) {
            if ($required) {
                Response::validation([$field => 'This field is required.']);
            }
            return null;
        }
        if (!is_string($value)) {
            Response::validation([$field => 'This field must be text.']);
        }
        $value = trim($value);
        if ($required && $value === '') {
            Response::validation([$field => 'This field is required.']);
        }
        if (mb_strlen($value) > $max) {
            Response::validation([$field => 'This field may not exceed ' . $max . ' characters.']);
        }
        return $value;
    }

    private static function progress(array $body, string $field = 'progress'): ?int
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        $value = $body[$field];
        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > 100) {
            Response::validation([$field => 'Progress must be an integer between 0 and 100.']);
        }
        return $value;
    }

    private static function status(array $body, array $allowed): ?string
    {
        if (!array_key_exists('status', $body)) {
            return null;
        }
        $value = strtolower(trim((string) $body['status']));
        if (!in_array($value, $allowed, true)) {
            Response::validation(['status' => 'Invalid status value.']);
        }
        return $value;
    }

    private static function shapePlan(array $row, array $activities): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'focus_area' => $row['focus_area'],
            'description' => $row['description'],
            'target_date' => $row['target_date'],
            'status' => $row['status'],
            'progress' => (int) $row['progress'],
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'activities' => array_map([self::class, 'shapeActivity'], $activities),
        ];
    }

    private static function shapeActivity(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'plan_id' => (int) $row['plan_id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'target_date' => $row['target_date'],
            'status' => $row['status'],
            'progress' => (int) $row['progress'],
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function activities(int $planId): array
    {
        $stmt = db()->prepare(
            'SELECT * FROM development_activities WHERE plan_id = :plan_id ORDER BY (target_date IS NULL), target_date, id'
        );
        $stmt->execute([':plan_id' => $planId]);
        return $stmt->fetchAll();
    }

    public static function index(): never
    {
        $employeeId = self::employeeId();
        $stmt = db()->prepare(
            'SELECT * FROM development_plans WHERE employee_id = :employee_id ORDER BY (target_date IS NULL), target_date, id DESC'
        );
        $stmt->execute([':employee_id' => $employeeId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = self::shapePlan($row, self::activities((int) $row['id']));
        }
        Response::list($items, 'Development plans retrieved successfully.', 1, count($items), count($items));
    }

    public static function store(): never
    {
        $employeeId = self::employeeId();
        $body = api_body();
        $title = self::text($body, 'title', self::MAX_TITLE, true);
        $focusArea = self::text($body, 'focus_area', self::MAX_FOCUS);
        $description = self::text($body, 'description', self::MAX_DESCRIPTION);
        $targetDate = self::dateValue($body, 'target_date');
        $notes = self::text($body, 'notes', self::MAX_NOTES);

        $stmt = db()->prepare(
            'INSERT INTO development_plans
             (employee_id, title, focus_area, description, target_date, notes)
             VALUES (:employee_id, :title, :focus_area, :description, :target_date, :notes)'
        );
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':title' => $title,
            ':focus_area' => $focusArea,
            ':description' => $description,
            ':target_date' => $targetDate,
            ':notes' => $notes,
        ]);
        $id = (int) db()->lastInsertId();
        Response::created(self::shapePlan(self::plan($employeeId, $id), []), 'Development plan created successfully.');
    }

    public static function update(int $planId): never
    {
        $employeeId = self::employeeId();
        $plan = self::plan($employeeId, $planId);
        if (!$plan) {
            Response::notFound('Development plan not found.');
        }
        $body = api_body();
        $sets = [];
        $params = [':id' => $planId, ':employee_id' => $employeeId];

        foreach ([
            'title' => [self::MAX_TITLE, true],
            'focus_area' => [self::MAX_FOCUS, false],
            'description' => [self::MAX_DESCRIPTION, false],
            'notes' => [self::MAX_NOTES, false],
        ] as $field => [$max, $required]) {
            if (array_key_exists($field, $body)) {
                $sets[] = "$field = :$field";
                $params[":$field"] = self::text($body, $field, $max, $required);
            }
        }
        if (array_key_exists('target_date', $body)) {
            $sets[] = 'target_date = :target_date';
            $params[':target_date'] = self::dateValue($body, 'target_date');
        }
        $progress = self::progress($body);
        if ($progress !== null) {
            $sets[] = 'progress = :progress';
            $params[':progress'] = $progress;
        }
        $status = self::status($body, self::PLAN_STATUSES);
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params[':status'] = $status;
        }
        if (!$sets) {
            Response::validation(['plan' => 'Provide at least one field to update.'], 'Nothing to update.');
        }
        db()->prepare(
            'UPDATE development_plans SET ' . implode(', ', $sets)
            . ' WHERE id = :id AND employee_id = :employee_id'
        )->execute($params);
        $fresh = self::plan($employeeId, $planId);
        Response::item(self::shapePlan($fresh, self::activities($planId)), 'Development plan updated successfully.');
    }

    public static function storeActivity(int $planId): never
    {
        $employeeId = self::employeeId();
        if (!self::plan($employeeId, $planId)) {
            Response::notFound('Development plan not found.');
        }
        $body = api_body();
        $title = self::text($body, 'title', self::MAX_TITLE, true);
        $description = self::text($body, 'description', self::MAX_DESCRIPTION);
        $targetDate = self::dateValue($body, 'target_date');
        $notes = self::text($body, 'notes', self::MAX_NOTES);

        $stmt = db()->prepare(
            'INSERT INTO development_activities
             (plan_id, title, description, target_date, notes)
             VALUES (:plan_id, :title, :description, :target_date, :notes)'
        );
        $stmt->execute([
            ':plan_id' => $planId,
            ':title' => $title,
            ':description' => $description,
            ':target_date' => $targetDate,
            ':notes' => $notes,
        ]);
        $id = (int) db()->lastInsertId();
        Response::created(self::shapeActivity(self::activity($employeeId, $planId, $id)), 'Development activity created successfully.');
    }

    public static function updateActivity(int $planId, int $activityId): never
    {
        $employeeId = self::employeeId();
        if (!self::activity($employeeId, $planId, $activityId)) {
            Response::notFound('Development activity not found.');
        }
        $body = api_body();
        $sets = [];
        $params = [':id' => $activityId, ':plan_id' => $planId];
        foreach ([
            'title' => [self::MAX_TITLE, true],
            'description' => [self::MAX_DESCRIPTION, false],
            'notes' => [self::MAX_NOTES, false],
        ] as $field => [$max, $required]) {
            if (array_key_exists($field, $body)) {
                $sets[] = "$field = :$field";
                $params[":$field"] = self::text($body, $field, $max, $required);
            }
        }
        if (array_key_exists('target_date', $body)) {
            $sets[] = 'target_date = :target_date';
            $params[':target_date'] = self::dateValue($body, 'target_date');
        }
        $progress = self::progress($body);
        if ($progress !== null) {
            $sets[] = 'progress = :progress';
            $params[':progress'] = $progress;
        }
        $status = self::status($body, self::ACTIVITY_STATUSES);
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params[':status'] = $status;
        }
        if (!$sets) {
            Response::validation(['activity' => 'Provide at least one field to update.'], 'Nothing to update.');
        }
        db()->prepare(
            'UPDATE development_activities SET ' . implode(', ', $sets)
            . ' WHERE id = :id AND plan_id = :plan_id'
        )->execute($params);
        Response::item(
            self::shapeActivity(self::activity($employeeId, $planId, $activityId)),
            'Development activity updated successfully.'
        );
    }
}
