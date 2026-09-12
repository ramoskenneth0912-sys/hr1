<?php
/**
 * /api/v1/employee/competencies  and  /api/v1/admin/competencies
 * — Employee Self-Service "My Competencies" (Phase 3A).
 *
 * EMPLOYEE ENDPOINTS (own records only):
 *   GET    employee/competencies           → index      (list own assignments)
 *   GET    employee/competencies/{id}      → show       (own assignment detail)
 *
 * ADMIN ENDPOINTS (hr/manager, manager scope enforced):
 *   Competency catalog (managed by HR; managers can read):
 *   GET    admin/competencies                     → catalogIndex
 *   GET    admin/competencies/{id}                → catalogShow
 *   POST   admin/competencies                     → catalogStore   (HR only)
 *   PUT/PATCH admin/competencies/{id}             → catalogUpdate  (HR only)
 *   DELETE admin/competencies/{id}                → catalogDestroy (HR only, archive)
 *
 *   Employee competency assignments (HR full; manager-scoped read + scoring):
 *   GET    admin/competencies/employees           → assignmentsIndex
 *   GET    admin/competencies/employees/{id}      → assignmentsShow
 *   POST   admin/competencies/employees           → assignmentsStore  (HR only)
 *   PUT/PATCH admin/competencies/employees/{id}   → assignmentsUpdate (HR full; manager-scoped evaluation)
 *   DELETE admin/competencies/employees/{id}      → assignmentsDestroy (HR only)
 *
 * SECURITY MODEL (mirrors PerformanceController):
 *   - Ownership on employee endpoints is ALWAYS resolved from the authenticated
 *     user's employee record (users.employee_id) via the existing session/
 *     Bearer chain — never from client-supplied identifiers.
 *   - A user with role 'employee' OR 'manager' (a manager is also an employee)
 *     may read their OWN competency assignments through /employee/competencies.
 *   - /admin/competencies requires Auth::requireAdmin() (hr/manager). A manager
 *     may only reach employees where employees.manager_id equals the manager's
 *     own employee id. HR has full scope.
 *   - Employee CRUD boundaries: employees may only VIEW their own assignments.
 *     They may NEVER modify levels, evaluator info, other employees, delete
 *     assignments, or touch the catalog.
 *   - Management scope boundaries: managers may evaluate (set current_level +
 *     notes) employees in their scope; only HR may assign competencies, set
 *     required_level / evaluator fields, or edit the catalog.
 *   - 404 (never 403) for entities outside the caller's reach to avoid
 *     revealing whether a record exists.
 *
 * LEVEL MODEL:
 *   required_level / current_level are integers 1-5 mapped to the shared
 *   rating_options scale ('competency', seeded in migration 025). Labels are
 *   resolved at read time; no duplicate level table exists.
 *
 * CATALOG DELETION:
 *   Catalog deletion is a soft archive (is_active = 0). employee_competencies
 *   references competencies ON DELETE CASCADE, so physical deletion would
 *   silently destroy every employee assignment — that is intentionally not
 *   exposed. HR explicitly removes assignments first, then archives the entry.
 *
 * FUTURE INTEGRATION:
 *   The catalog is intentionally NOT copied into performance_reviews. A later
 *   phase will add review_competency_results that references
 *   employee_competencies (same snapshot pattern as review_goal_results).
 */

declare(strict_types=1);

class CompetenciesController
{
    private const LEVEL_MIN = 1;
    private const LEVEL_MAX = 5;

    private const MAX_NAME = 120;
    private const MAX_CATEGORY = 60;
    private const MAX_DESCRIPTION = 4000;
    private const MAX_NOTE_LENGTH = 2000;

    private const ASSIGNMENT_STATUSES = ['active', 'inactive'];

    /** Roles permitted to act as an "employee" on their own assignments. */
    private const OWNER_ROLES = ['employee', 'manager'];

    // ------------------------------------------------------------------
    // Auth / context helpers
    // ------------------------------------------------------------------

    /** Authenticated user + their linked employee_id (0 when none). */
    private static function ctx(): array
    {
        $user = Auth::requireAuth();
        $stmt = db()->prepare('SELECT employee_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $user['id']]);
        $employeeId = (int) ($stmt->fetchColumn() ?: 0);
        return [
            'user'        => $user,
            'role'        => (string) ($user['role'] ?? ''),
            'id'          => (int) $user['id'],
            'employee_id' => $employeeId,
        ];
    }

    /** Employee-scoped context for own-assignment endpoints. */
    private static function ownCtx(): array
    {
        $ctx = self::ctx();
        if (!in_array($ctx['role'], self::OWNER_ROLES, true)) {
            Response::forbidden('Competencies are available to employees only.');
        }
        if ($ctx['employee_id'] <= 0) {
            Response::forbidden('No employee record is linked to your account.');
        }
        return $ctx;
    }

    /** Admin (hr/manager) context for management endpoints. */
    private static function adminCtx(): array
    {
        Auth::requireAdmin();
        return self::ctx();
    }

    /** Manager-scope guard for a target employee (HR always passes). */
    private static function checkEmployeeScope(int $targetEmployeeId, array $ctx): void
    {
        if ($ctx['role'] === 'hr') {
            return;
        }
        if ($ctx['role'] !== 'manager' || $ctx['employee_id'] <= 0) {
            Response::forbidden('You may only access the employees assigned to you.');
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM employees WHERE id = :eid AND manager_id = :mgr LIMIT 1');
        $stmt->execute([':eid' => $targetEmployeeId, ':mgr' => $ctx['employee_id']]);
        if ((int) $stmt->fetchColumn() <= 0) {
            Response::forbidden('You may only access the employees assigned to you.');
        }
    }

    /** Require the role hr (catalog + assignment creation/destruction are HR-only). */
    private static function requireHr(array $ctx): void
    {
        if ($ctx['role'] !== 'hr') {
            Response::forbidden('Only HR can perform this action.');
        }
    }

    // ------------------------------------------------------------------
    // Query / shape helpers
    // ------------------------------------------------------------------

    private static function ratingLabels(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (db()->query('SELECT scale_code, value, label FROM rating_options') as $r) {
                $map[$r['scale_code'] . ':' . $r['value']] = $r['label'];
            }
        }
        return $map;
    }

    private static function labelFor(string $scale, int $value, ?string $fallback = null): ?string
    {
        return self::ratingLabels()[$scale . ':' . $value] ?? $fallback;
    }

    /** Evaluator display name: employee name when available, else username. */
    private static function evaluatorName(array $r): ?string
    {
        $name = trim(($r['evaluator_first_name'] ?? '') . ' ' . ($r['evaluator_last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        return $r['evaluator_username'] ?? null;
    }

    /** Public JSON shape for one employee assignment (employee-facing). */
    private static function employeeShape(array $r): array
    {
        $current = self::intOrNull($r['current_level']);
        $evaluated = $current !== null;
        $met = $evaluated ? $current >= (int) $r['required_level'] : null;
        $gap = $evaluated ? max(0, (int) $r['required_level'] - $current) : null;

        return [
            'id'                    => (int) $r['id'],
            'competency_id'         => (int) $r['competency_id'],
            'competency_name'       => $r['competency_name'] ?? null,
            'description'           => $r['competency_description'] ?? null,
            'category'              => $r['competency_category'] ?? null,
            'required_level'        => (int) $r['required_level'],
            'required_level_label'  => self::labelFor('competency', (int) $r['required_level'], null),
            'current_level'         => $current,
            'current_level_label'   => $evaluated ? self::labelFor('competency', $current, null) : null,
            'evaluated'             => $evaluated,
            'met'                   => $met,
            'gap'                   => $gap,
            'status'                => $r['status'],
            'notes'                 => $r['notes'],
            'evaluator_name'        => self::evaluatorName($r),
            'evaluator_username'    => $r['evaluator_username'] ?? null,
            'evaluated_at'          => $r['evaluated_at'],
            'created_at'            => $r['created_at'],
            'updated_at'            => $r['updated_at'],
        ];
    }

    /** Admin JSON shape for an assignment (adds employee context). */
    private static function adminAssignmentShape(array $r): array
    {
        $shape = self::employeeShape($r);
        $shape['employee'] = [
            'id'            => (int) $r['employee_id'],
            'employee_no'   => $r['employee_no'] ?? null,
            'name'          => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'job_title'     => $r['job_title'] ?? null,
            'department_id' => self::intOrNull($r['department_id'] ?? null),
            'manager_id'    => self::intOrNull($r['manager_id'] ?? null),
        ];
        return $shape;
    }

    private static function catalogShape(array $r): array
    {
        return [
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'description' => $r['description'],
            'category'    => $r['category'],
            'is_active'   => (int) $r['is_active'] === 1,
            'created_by'  => self::intOrNull($r['created_by'] ?? null),
            'created_at'  => $r['created_at'],
            'updated_at'  => $r['updated_at'],
        ];
    }

    // ------------------------------------------------------------------
    // Validation helpers (exit via Response on failure)
    // ------------------------------------------------------------------

    private static function intOrError(mixed $v, string $field): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\d+$/', $v)) {
            return (int) $v;
        }
        Response::validation([$field => $field . ' must be a whole number.'], 'Validation failed.');
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    private static function levelOrError(mixed $v, string $field): int
    {
        $l = self::intOrError($v, $field);
        if ($l < self::LEVEL_MIN || $l > self::LEVEL_MAX) {
            Response::validation(
                [$field => $field . ' must be between ' . self::LEVEL_MIN . ' and ' . self::LEVEL_MAX . '.'],
                'Validation failed.'
            );
        }
        return $l;
    }

    private static function nullableLevelOrError(mixed $v, string $field): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return self::levelOrError($v, $field);
    }

    private static function textOrError(mixed $v, string $field, int $max): string
    {
        if (!is_string($v)) {
            Response::validation([$field => $field . ' must be text.'], 'Validation failed.');
        }
        $t = trim($v);
        if (mb_strlen($t) > $max) {
            Response::validation(
                [$field => $field . ' may not exceed ' . $max . ' characters.'],
                'Validation failed.'
            );
        }
        return $t;
    }

    private static function nullableTextOrNull(mixed $v, string $field, int $max): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return self::textOrError($v, $field, $max);
    }

    private static function datetimeOrError(mixed $v, string $field): string
    {
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?)?/', $v)) {
            $n = strtotime($v);
            if ($n !== false) {
                return date('Y-m-d H:i:s', $n);
            }
        }
        Response::validation([$field => $field . ' must use the YYYY-MM-DD or YYYY-MM-DD HH:MM:SS format.'], 'Validation failed.');
    }

    private static function nullableDatetimeOrNull(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return self::datetimeOrError($v, $field);
    }

    /** Look up an existing active/inactive catalog row. */
    private static function catalogRow(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM competencies WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function existingEmployee(int $employeeId): bool
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM employees WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $employeeId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Assignment row joined with competency + employee + evaluator. */
    private static function assignmentRow(int $id, bool $admin): ?array
    {
        $cols = $admin
            ? 'e.employee_no, e.first_name, e.last_name, e.job_title, e.department_id, e.manager_id,'
            : 'NULL AS employee_no, NULL AS first_name, NULL AS last_name, NULL AS job_title, NULL AS department_id, NULL AS manager_id,';
        $stmt = db()->prepare(
            'SELECT ec.*, c.name AS competency_name, c.description AS competency_description,
                    c.category AS competency_category,
                    e.id AS employee_id, ' . $cols . '
                    u.username AS evaluator_username,
                    ee.first_name AS evaluator_first_name, ee.last_name AS evaluator_last_name
             FROM employee_competencies ec
             JOIN competencies c ON c.id = ec.competency_id
             JOIN employees e ON e.id = ec.employee_id
             LEFT JOIN users u ON u.id = ec.evaluated_by
             LEFT JOIN employees ee ON ee.id = u.employee_id
             WHERE ec.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ------------------------------------------------------------------
    // EMPLOYEE ENDPOINTS
    // ------------------------------------------------------------------

    /** GET employee/competencies — list the caller's own assignments. */
    public static function index(): never
    {
        $ctx = self::ownCtx();
        $stmt = db()->prepare(
            'SELECT ec.*, c.name AS competency_name, c.description AS competency_description,
                    c.category AS competency_category,
                    u.username AS evaluator_username,
                    ee.first_name AS evaluator_first_name, ee.last_name AS evaluator_last_name
             FROM employee_competencies ec
             JOIN competencies c ON c.id = ec.competency_id
             LEFT JOIN users u ON u.id = ec.evaluated_by
             LEFT JOIN employees ee ON ee.id = u.employee_id
             WHERE ec.employee_id = :eid
             ORDER BY ec.status ASC, c.name ASC'
        );
        $stmt->execute([':eid' => $ctx['employee_id']]);
        $items = array_map([self::class, 'employeeShape'], $stmt->fetchAll());
        Response::list($items, 'Competencies retrieved successfully.', 1, count($items), count($items));
    }

    /** GET employee/competencies/{id} — own assignment detail. */
    public static function show(int $id): never
    {
        $ctx = self::ownCtx();
        $row = self::assignmentRow($id, false);
        if (!$row || (int) $row['employee_id'] !== $ctx['employee_id']) {
            Response::notFound('Competency assignment not found.');
        }
        Response::item(self::employeeShape($row), 'Competency assignment retrieved successfully.');
    }

    // ------------------------------------------------------------------
    // ADMIN / MANAGER ENDPOINTS — competency catalog
    // ------------------------------------------------------------------

    /** GET admin/competencies — list catalog entries (hr/manager). */
    public static function catalogIndex(): never
    {
        self::adminCtx();
        $where = [];
        $params = [];
        $active = api_query('active');
        if ($active !== null) {
            $flag = in_array($active, ['1', 'true', 'yes'], true) ? 1 : 0;
            $where[] = 'c.is_active = :active';
            $params[':active'] = $flag;
        }
        $category = api_query('category');
        if ($category !== null) {
            $where[] = 'c.category = :category';
            $params[':category'] = self::textOrError($category, 'category', self::MAX_CATEGORY);
        }
        $search = api_query('q');
        if ($search !== null) {
            $where[] = '(c.name LIKE :q OR c.category LIKE :q)';
            $params[':q'] = '%' . self::textOrError($search, 'q', 120) . '%';
        }
        $sql = 'SELECT * FROM competencies c';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.name ASC, c.id DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $items = array_map([self::class, 'catalogShape'], $stmt->fetchAll());
        Response::list($items, 'Competencies retrieved successfully.', 1, count($items), count($items));
    }

    /** GET admin/competencies/{id} — catalog detail (hr/manager). */
    public static function catalogShow(int $id): never
    {
        self::adminCtx();
        $row = self::catalogRow($id);
        if (!$row) {
            Response::notFound('Competency not found.');
        }
        Response::item(self::catalogShape($row), 'Competency retrieved successfully.');
    }

    /** POST admin/competencies — create a catalog entry (HR only). */
    public static function catalogStore(): never
    {
        $ctx = self::adminCtx();
        self::requireHr($ctx);

        $body = api_body();
        $name = self::textOrError($body['name'] ?? null, 'name', self::MAX_NAME);
        if ($name === '') {
            Response::validation(['name' => 'name is required.'], 'Validation failed.');
        }
        $category = self::nullableTextOrNull($body['category'] ?? null, 'category', self::MAX_CATEGORY);
        $description = self::nullableTextOrNull($body['description'] ?? null, 'description', self::MAX_DESCRIPTION);
        $isActive = 1;
        if (array_key_exists('is_active', $body)) {
            $isActive = in_array($body['is_active'], [1, '1', true, 'true'], true) ? 1 : 0;
        }

        $dup = db()->prepare('SELECT id FROM competencies WHERE name = :name LIMIT 1');
        $dup->execute([':name' => $name]);
        if ($dup->fetch()) {
            Response::conflict('A competency with this name already exists.');
        }

        $stmt = db()->prepare(
            'INSERT INTO competencies (name, description, category, is_active, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $description, $category, $isActive, $ctx['id']]);
        $row = self::catalogRow((int) db()->lastInsertId());
        Response::created(self::catalogShape($row), 'Competency created.');
    }

    /** PUT/PATCH admin/competencies/{id} — update a catalog entry (HR only). */
    public static function catalogUpdate(int $id, bool $isPatch): never
    {
        $ctx = self::adminCtx();
        self::requireHr($ctx);
        $row = self::catalogRow($id);
        if (!$row) {
            Response::notFound('Competency not found.');
        }

        $body = api_body();
        $sets = [];
        $params = [':id' => $id];

        // PATCH supports empty-body updates gracefully; PUT requires at least one field.
        if (!$isPatch && !$body) {
            Response::validation(['name' => 'Provide at least one field to update.'], 'Validation failed.');
        }

        if (array_key_exists('name', $body)) {
            $name = self::textOrError($body['name'], 'name', self::MAX_NAME);
            if ($name === '') {
                Response::validation(['name' => 'name must not be empty.'], 'Validation failed.');
            }
            $dup = db()->prepare('SELECT id FROM competencies WHERE name = :name AND id <> :id LIMIT 1');
            $dup->execute([':name' => $name, ':id' => $id]);
            if ($dup->fetch()) {
                Response::conflict('A competency with this name already exists.');
            }
            $sets[] = 'name = :name';
            $params[':name'] = $name;
        }
        if (array_key_exists('category', $body)) {
            $sets[] = 'category = :category';
            $params[':category'] = self::nullableTextOrNull($body['category'], 'category', self::MAX_CATEGORY);
        }
        if (array_key_exists('description', $body)) {
            $sets[] = 'description = :description';
            $params[':description'] = self::nullableTextOrNull($body['description'], 'description', self::MAX_DESCRIPTION);
        }
        if (array_key_exists('is_active', $body)) {
            $sets[] = 'is_active = :active';
            $params[':active'] = in_array($body['is_active'], [1, '1', true, 'true'], true) ? 1 : 0;
        }
        if (!$sets) {
            // PATCH with no recognized fields — idempotent no-op, return current entity.
            Response::item(self::catalogShape(self::catalogRow($id)), 'Competency updated.');
        }

        db()->prepare('UPDATE competencies SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        Response::item(self::catalogShape(self::catalogRow($id)), 'Competency updated.');
    }

    /**
     * DELETE admin/competencies/{id} — archive a catalog entry (HR only).
     * Soft-deletes (is_active = 0) so employee assignments are preserved.
     */
    public static function catalogDestroy(int $id): never
    {
        $ctx = self::adminCtx();
        self::requireHr($ctx);
        $row = self::catalogRow($id);
        if (!$row) {
            Response::notFound('Competency not found.');
        }
        db()->prepare('UPDATE competencies SET is_active = 0 WHERE id = :id')->execute([':id' => $id]);
        Response::item(self::catalogShape(self::catalogRow($id)), 'Competency archived. Existing assignments are preserved.');
    }

    // ------------------------------------------------------------------
    // ADMIN / MANAGER ENDPOINTS — employee assignments
    // ------------------------------------------------------------------

    /** GET admin/competencies/employees — list assignments (hr/manager, scoped). */
    public static function assignmentsIndex(): never
    {
        $ctx = self::adminCtx();
        $where = [];
        $params = [];
        if ($ctx['role'] !== 'hr') {
            $where[] = 'e.manager_id = :mgr_emp';
            $params[':mgr_emp'] = $ctx['employee_id'];
        }

        $employeeId = api_query('employee_id');
        if ($employeeId !== null) {
            $eid = self::intOrError($employeeId, 'employee_id');
            if ($ctx['role'] !== 'hr') {
                self::checkEmployeeScope($eid, $ctx);
            }
            $where[] = 'ec.employee_id = :employee_id';
            $params[':employee_id'] = $eid;
        }
        $competencyId = api_query('competency_id');
        if ($competencyId !== null) {
            $where[] = 'ec.competency_id = :competency_id';
            $params[':competency_id'] = self::intOrError($competencyId, 'competency_id');
        }
        $status = api_query('status');
        if ($status !== null) {
            $s = strtolower(trim((string) $status));
            if (!in_array($s, self::ASSIGNMENT_STATUSES, true)) {
                Response::validation(['status' => 'status must be one of: ' . implode(', ', self::ASSIGNMENT_STATUSES) . '.'], 'Validation failed.');
            }
            $where[] = 'ec.status = :status';
            $params[':status'] = $s;
        }

        $sql = 'SELECT ec.*, c.name AS competency_name, c.description AS competency_description,
                       c.category AS competency_category,
                       e.employee_no, e.first_name, e.last_name, e.job_title, e.department_id, e.manager_id,
                       u.username AS evaluator_username,
                       ee.first_name AS evaluator_first_name, ee.last_name AS evaluator_last_name
                FROM employee_competencies ec
                JOIN competencies c ON c.id = ec.competency_id
                JOIN employees e ON e.id = ec.employee_id
                LEFT JOIN users u ON u.id = ec.evaluated_by
                LEFT JOIN employees ee ON ee.id = u.employee_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.last_name ASC, c.name ASC, ec.id DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $items = array_map([self::class, 'adminAssignmentShape'], $stmt->fetchAll());
        Response::list($items, 'Employee competency assignments retrieved successfully.', 1, count($items), count($items));
    }

    /** GET admin/competencies/employees/{id} — one assignment (hr/manager, scoped). */
    public static function assignmentsShow(int $id): never
    {
        $ctx = self::adminCtx();
        $row = self::assignmentRow($id, true);
        if (!$row) {
            Response::notFound('Competency assignment not found.');
        }
        self::checkEmployeeScope((int) $row['employee_id'], $ctx);
        Response::item(self::adminAssignmentShape($row), 'Competency assignment retrieved successfully.');
    }

    /** POST admin/competencies/employees — assign a competency (HR only). */
    public static function assignmentsStore(): never
    {
        $ctx = self::adminCtx();
        self::requireHr($ctx);

        $body = api_body();
        $employeeId = self::intOrError($body['employee_id'] ?? null, 'employee_id');
        if (!self::existingEmployee($employeeId)) {
            Response::notFound('Employee not found.');
        }

        $competencyId = self::intOrError($body['competency_id'] ?? null, 'competency_id');
        $comp = self::catalogRow($competencyId);
        if (!$comp) {
            Response::notFound('Competency not found.');
        }
        if ((int) $comp['is_active'] !== 1) {
            Response::validation(['competency_id' => 'Cannot assign an archived competency.'], 'Validation failed.');
        }

        $requiredLevel = self::levelOrError($body['required_level'] ?? null, 'required_level');
        $currentLevel = self::nullableLevelOrError($body['current_level'] ?? null, 'current_level');
        $status = strtolower(trim((string) ($body['status'] ?? 'active')));
        if (!in_array($status, self::ASSIGNMENT_STATUSES, true)) {
            Response::validation(['status' => 'status must be one of: ' . implode(', ', self::ASSIGNMENT_STATUSES) . '.'], 'Validation failed.');
        }
        $notes = self::nullableTextOrNull($body['notes'] ?? null, 'notes', self::MAX_NOTE_LENGTH);

        // Evaluator fields only when a current level is being recorded.
        $evaluatedBy = null;
        $evaluatedAt = null;
        if ($currentLevel !== null) {
            $evaluatedBy = $ctx['id'];
            $evaluatedAt = date('Y-m-d H:i:s');
            if (array_key_exists('evaluated_by', $body) && $body['evaluated_by'] !== null && $body['evaluated_by'] !== '') {
                $evaluatedBy = self::intOrError($body['evaluated_by'], 'evaluated_by');
                $u = db()->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
                $u->execute([':id' => $evaluatedBy]);
                if (!$u->fetch()) {
                    Response::validation(['evaluated_by' => 'Evaluator user does not exist or is inactive.'], 'Validation failed.');
                }
            }
            if (array_key_exists('evaluated_at', $body) && $body['evaluated_at'] !== null && $body['evaluated_at'] !== '') {
                $evaluatedAt = self::datetimeOrError($body['evaluated_at'], 'evaluated_at');
            }
        }

        $dup = db()->prepare('SELECT id FROM employee_competencies WHERE employee_id = :eid AND competency_id = :cid LIMIT 1');
        $dup->execute([':eid' => $employeeId, ':cid' => $competencyId]);
        if ($dup->fetch()) {
            Response::conflict('This competency is already assigned to the employee.');
        }

        $stmt = db()->prepare(
            'INSERT INTO employee_competencies
               (employee_id, competency_id, required_level, current_level, evaluated_by, evaluated_at, status, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$employeeId, $competencyId, $requiredLevel, $currentLevel, $evaluatedBy, $evaluatedAt, $status, $notes, $ctx['id']]);

        $row = self::assignmentRow((int) db()->lastInsertId(), true);
        Response::created(self::adminAssignmentShape($row), 'Competency assigned.');
    }

    /**
     * PUT/PATCH admin/competencies/employees/{id} — update an assignment.
     * HR: full update (required_level, current_level, evaluator fields, status, notes).
     * Manager: scoped to evaluating current_level + notes + status on their own employees.
     */
    public static function assignmentsUpdate(int $id, bool $isPatch): never
    {
        $ctx = self::adminCtx();
        $row = self::assignmentRow($id, true);
        if (!$row) {
            Response::notFound('Competency assignment not found.');
        }
        self::checkEmployeeScope((int) $row['employee_id'], $ctx);

        $isHr = $ctx['role'] === 'hr';
        $body = api_body();
        if (!$isPatch && !$body) {
            Response::validation(['required_level' => 'Provide at least one field to update.'], 'Validation failed.');
        }

        $sets = [];
        $params = [':id' => $id];

        // --- allowed: current_level, notes, status (employee + manager) ---
        if (array_key_exists('current_level', $body)) {
            $currentLevel = self::nullableLevelOrError($body['current_level'], 'current_level');
            $sets[] = 'current_level = :current_level';
            $params[':current_level'] = $currentLevel;
            $sets[] = 'evaluated_by = :evaluated_by';
            $params[':evaluated_by'] = $ctx['id'];
            $sets[] = 'evaluated_at = :evaluated_at';
            $params[':evaluated_at'] = date('Y-m-d H:i:s');
        }
        if (array_key_exists('notes', $body)) {
            $sets[] = 'notes = :notes';
            $params[':notes'] = self::nullableTextOrNull($body['notes'], 'notes', self::MAX_NOTE_LENGTH);
        }
        if (array_key_exists('status', $body)) {
            $s = strtolower(trim((string) $body['status']));
            if (!in_array($s, self::ASSIGNMENT_STATUSES, true)) {
                Response::validation(['status' => 'status must be one of: ' . implode(', ', self::ASSIGNMENT_STATUSES) . '.'], 'Validation failed.');
            }
            $sets[] = 'status = :status';
            $params[':status'] = $s;
        }

        // --- HR-only: required_level, competency_id, evaluator fields ---
        if ($isHr) {
            if (array_key_exists('required_level', $body)) {
                $sets[] = 'required_level = :required_level';
                $params[':required_level'] = self::levelOrError($body['required_level'], 'required_level');
            }
            if (array_key_exists('competency_id', $body)) {
                $competencyId = self::intOrError($body['competency_id'], 'competency_id');
                $comp = self::catalogRow($competencyId);
                if (!$comp) {
                    Response::notFound('Competency not found.');
                }
                if ((int) $comp['is_active'] !== 1) {
                    Response::validation(['competency_id' => 'Cannot assign an archived competency.'], 'Validation failed.');
                }
                $dup = db()->prepare(
                    'SELECT id FROM employee_competencies WHERE employee_id = :eid AND competency_id = :cid AND id <> :id LIMIT 1'
                );
                $dup->execute([':eid' => (int) $row['employee_id'], ':cid' => $competencyId, ':id' => $id]);
                if ($dup->fetch()) {
                    Response::conflict('This competency is already assigned to the employee.');
                }
                $sets[] = 'competency_id = :competency_id';
                $params[':competency_id'] = $competencyId;
            }
            if (array_key_exists('evaluated_by', $body)) {
                $eb = self::intOrError($body['evaluated_by'] ?? $ctx['id'], 'evaluated_by');
                $u = db()->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
                $u->execute([':id' => $eb]);
                if (!$u->fetch()) {
                    Response::validation(['evaluated_by' => 'Evaluator user does not exist or is inactive.'], 'Validation failed.');
                }
                $sets[] = 'evaluated_by = :evaluated_by';
                $params[':evaluated_by'] = $eb;
            }
            if (array_key_exists('evaluated_at', $body)) {
                $sets[] = 'evaluated_at = :evaluated_at';
                $params[':evaluated_at'] = self::nullableDatetimeOrNull($body['evaluated_at'], 'evaluated_at');
            }
        }

        if (!$sets) {
            // PATCH with no recognized fields — idempotent no-op, return current entity.
            Response::item(self::adminAssignmentShape($row), 'Competency assignment updated.');
        }

        db()->prepare('UPDATE employee_competencies SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        $fresh = self::assignmentRow($id, true);
        Response::item(self::adminAssignmentShape($fresh), 'Competency assignment updated.');
    }

    /** DELETE admin/competencies/employees/{id} — remove an assignment (HR only). */
    public static function assignmentsDestroy(int $id): never
    {
        $ctx = self::adminCtx();
        self::requireHr($ctx);
        $row = self::assignmentRow($id, true);
        if (!$row) {
            Response::notFound('Competency assignment not found.');
        }
        db()->prepare('DELETE FROM employee_competencies WHERE id = :id')->execute([':id' => $id]);
        Response::item(['id' => $id], 'Competency assignment removed.');
    }
}