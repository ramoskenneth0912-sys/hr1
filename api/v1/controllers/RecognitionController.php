<?php
/**
 * /api/v1/employee/recognition and /api/v1/admin/recognition.
 *
 * Employees may read only their published recognition records. HR and
 * authorized managers may create and publish records for employees in scope.
 */

declare(strict_types=1);

class RecognitionController
{
    private const MAX_CATEGORY = 80;
    private const MAX_TITLE = 160;
    private const MAX_MESSAGE = 4000;
    private const STATUSES = ['draft', 'published', 'archived'];

    private static function context(): array
    {
        $user = Auth::requireAuth();
        $stmt = db()->prepare('SELECT employee_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $user['id']]);

        return [
            'user' => $user,
            'role' => (string) ($user['role'] ?? ''),
            'user_id' => (int) $user['id'],
            'employee_id' => (int) ($stmt->fetchColumn() ?: 0),
        ];
    }

    private static function employeeContext(): array
    {
        $context = self::context();
        if (!in_array($context['role'], ['employee', 'manager'], true)) {
            Response::forbidden('Recognition is available to employees only.');
        }
        if ($context['employee_id'] <= 0) {
            Response::forbidden('No employee record is linked to your account.');
        }
        return $context;
    }

    private static function adminContext(): array
    {
        Auth::requireAdmin();
        return self::context();
    }

    private static function assertEmployeeScope(int $recipientEmployeeId, array $context): void
    {
        if ($context['role'] === 'hr') {
            return;
        }
        if ($context['role'] !== 'manager' || $context['employee_id'] <= 0) {
            Response::forbidden('You may only recognize employees in your scope.');
        }

        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM employees
             WHERE id = :employee_id AND manager_id = :manager_id
             LIMIT 1'
        );
        $stmt->execute([
            ':employee_id' => $recipientEmployeeId,
            ':manager_id' => $context['employee_id'],
        ]);
        if ((int) $stmt->fetchColumn() <= 0) {
            Response::forbidden('You may only recognize employees in your scope.');
        }
    }

    private static function text(array $body, string $field, int $max): string
    {
        if (!array_key_exists($field, $body) || !is_string($body[$field])) {
            Response::validation([$field => 'This field is required and must be text.']);
        }
        $value = trim($body[$field]);
        if ($value === '') {
            Response::validation([$field => 'This field is required.']);
        }
        if (mb_strlen($value) > $max) {
            Response::validation([$field => 'This field may not exceed ' . $max . ' characters.']);
        }
        return $value;
    }

    private static function dateValue(array $body): string
    {
        $value = self::text($body, 'recognition_date', 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            Response::validation(['recognition_date' => 'Date must use YYYY-MM-DD format.']);
        }
        return $value;
    }

    private static function status(array $body, string $default = 'draft'): string
    {
        if (!array_key_exists('status', $body)) {
            return $default;
        }
        $value = strtolower(trim((string) $body['status']));
        if (!in_array($value, self::STATUSES, true)) {
            Response::validation(['status' => 'Invalid recognition status.']);
        }
        return $value;
    }

    private static function employeeExists(int $employeeId): bool
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM employees WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $employeeId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function shape(array $row): array
    {
        $issuer = trim(($row['issuer_first_name'] ?? '') . ' ' . ($row['issuer_last_name'] ?? ''));
        return [
            'id' => (int) $row['id'],
            'category' => $row['category'],
            'title' => $row['title'],
            'message' => $row['message'],
            'recognition_date' => $row['recognition_date'],
            'given_by' => $issuer !== '' ? $issuer : ($row['issuer_username'] ?? null),
            'status' => $row['status'],
        ];
    }

    private static function selectBase(): string
    {
        return 'SELECT r.*, u.username AS issuer_username,
                       e.first_name AS issuer_first_name,
                       e.last_name AS issuer_last_name
                FROM employee_recognitions r
                INNER JOIN users u ON u.id = r.issuer_user_id
                LEFT JOIN employees e ON e.id = u.employee_id';
    }

    public static function index(): never
    {
        $context = self::employeeContext();
        $stmt = db()->prepare(
            self::selectBase() . '
             WHERE r.recipient_employee_id = :employee_id
               AND r.status = \'published\'
             ORDER BY r.recognition_date DESC, r.id DESC'
        );
        $stmt->execute([':employee_id' => $context['employee_id']]);
        $items = array_map([self::class, 'shape'], $stmt->fetchAll());
        Response::list($items, 'Recognition records retrieved successfully.', 1, count($items), count($items));
    }

    public static function store(): never
    {
        $context = self::adminContext();
        $body = api_body();
        $recipientId = filter_var($body['recipient_employee_id'] ?? null, FILTER_VALIDATE_INT);
        if ($recipientId === false || $recipientId === null || $recipientId <= 0) {
            Response::validation(['recipient_employee_id' => 'A valid recipient employee is required.']);
        }
        self::assertEmployeeScope((int) $recipientId, $context);
        if (!self::employeeExists((int) $recipientId)) {
            Response::validation(['recipient_employee_id' => 'Recipient employee was not found.']);
        }

        $category = self::text($body, 'category', self::MAX_CATEGORY);
        $title = self::text($body, 'title', self::MAX_TITLE);
        $message = self::text($body, 'message', self::MAX_MESSAGE);
        $date = self::dateValue($body);
        $status = self::status($body);

        $stmt = db()->prepare(
            'INSERT INTO employee_recognitions
             (recipient_employee_id, issuer_user_id, category, title, message, recognition_date, status)
             VALUES (:recipient, :issuer, :category, :title, :message, :recognition_date, :status)'
        );
        $stmt->execute([
            ':recipient' => (int) $recipientId,
            ':issuer' => $context['user_id'],
            ':category' => $category,
            ':title' => $title,
            ':message' => $message,
            ':recognition_date' => $date,
            ':status' => $status,
        ]);
        Response::created(['id' => (int) db()->lastInsertId()], 'Recognition record created successfully.');
    }

    public static function update(int $id): never
    {
        $context = self::adminContext();
        $stmt = db()->prepare('SELECT recipient_employee_id FROM employee_recognitions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::notFound('Recognition record not found.');
        }
        self::assertEmployeeScope((int) $row['recipient_employee_id'], $context);

        $body = api_body();
        $sets = [];
        $params = [':id' => $id];
        foreach ([
            'category' => self::MAX_CATEGORY,
            'title' => self::MAX_TITLE,
            'message' => self::MAX_MESSAGE,
        ] as $field => $max) {
            if (array_key_exists($field, $body)) {
                $sets[] = "$field = :$field";
                $params[":$field"] = self::text($body, $field, $max);
            }
        }
        if (array_key_exists('recognition_date', $body)) {
            $sets[] = 'recognition_date = :recognition_date';
            $params[':recognition_date'] = self::dateValue($body);
        }
        if (array_key_exists('status', $body)) {
            $sets[] = 'status = :status';
            $params[':status'] = self::status($body);
        }
        if (!$sets) {
            Response::validation(['recognition' => 'Provide at least one field to update.']);
        }

        db()->prepare(
            'UPDATE employee_recognitions SET ' . implode(', ', $sets) . ' WHERE id = :id'
        )->execute($params);
        Response::item(['id' => $id], 'Recognition record updated successfully.');
    }
}
