<?php
/**
 * /api/v1/employee/performance  and  /api/v1/admin/performance
 * — Employee Self-Service "My Performance" (Phase 2).
 *
 * EMPLOYEE ENDPOINTS (own records only):
 *   GET    employee/performance                     → index      (list own reviews)
 *   GET    employee/performance/{id}                → show       (own review detail)
 *   PATCH  employee/performance/{id}/self-assessment → draft      (save draft, no transition)
 *   POST   employee/performance/{id}/self-assessment → submit     (transition → manager_review)
 *   POST   employee/performance/{id}/acknowledge     → acknowledge (finalized → acknowledged)
 *
 * ADMIN ENDPOINTS (hr/manager, manager scope enforced):
 *   GET    admin/performance/periods                         → list periods
 *   POST   admin/performance/periods                         → create period (HR only)
 *   GET    admin/performance/options?employee_id=            → candidate goals + rating options
 *   GET    admin/performance/reviews                         → list reviews (scoped)
 *   POST   admin/performance/reviews                         → create + assign review
 *   GET    admin/performance/reviews/{id}                    → review detail (scoped)
 *   PATCH  admin/performance/reviews/{id}                    → transition + reviewer/rating/feedback/goals
 *   PUT    admin/performance/reviews/{id}/goal-results       → score linked goals
 *   PUT    admin/performance/reviews/{id}/manager-feedback   → feedback + manager rating
 *
 * SECURITY MODEL:
 *   - Ownership is always resolved from the authenticated user's employee
 *     record (session/Bearer), never from client-supplied identifiers.
 *   - An authenticated user with role 'employee' OR 'manager' (a manager is
 *     also an employee and has their own reviews) may access their OWN
 *     reviews through /employee/performance.
 *   - /admin/performance requires Auth::requireAdmin() (hr/manager). A
 *     manager may only reach reviews of employees where employees.manager_id
 *     equals the manager's own employee id, PLUS reviews where they are the
 *     explicit reviewer_user_id. HR has full scope.
 *   - 404 (never 403) for entities outside the caller's reach to avoid
 *     revealing whether a record exists.
 *
 * LIFECYCLE (enforced server-side, never directly writable):
 *   drafted → assigned → self_assessment → manager_review → finalized → acknowledged
 *   Employee-controlled: self-assessment submit, acknowledgement.
 *   Manager/HR-controlled: everything else. finalized/acknowledged are locked
 *   except the employee acknowledgement step.
 *
 * RATINGS (approved 1-5 scale, configurable via rating_options):
 *   Goal results, manager rating and the computed overall rating are all 1-5.
 *   The overall rating is computed at finalize as the weighted aggregate of
 *   the average linked-goal rating and the manager rating (default 50/50,
 *   overridable per review via weight_config).
 *
 * GREAT-GOALS INTEGRATION:
 *   review_goal_results REFERENCES employee_goals (no duplication). Each link
 *   keeps a POINT-IN-TIME snapshot of the goal's title/status/progress so a
 *   finalized review reflects the state the goal had at review time.
 *
 * COMPETENCIES:
 *   Not implemented — the competency catalog does not exist yet.
 */

declare(strict_types=1);

class PerformanceController
{
    private const RATING_MIN = 1;
    private const RATING_MAX = 5;
    private const MAX_SELF_FIELD = 2000;
    private const MAX_COMMENTS = 4000;
    private const MAX_NOTE_LENGTH = 2000;
    private const MAX_FEEDBACK_LENGTH = 5000;
    private const DEFAULT_WEIGHTS = ['goal' => 50, 'manager' => 50];

    /** Roles permitted to act as an "employee" on their own reviews. */
    private const OWNER_ROLES = ['employee', 'manager'];

    private const PERIOD_TYPES = ['annual', 'semi_annual', 'quarterly', 'probationary'];
    private const PERIOD_STATUSES = ['planned', 'open', 'closed'];
    private const REVIEW_STATUSES = ['drafted', 'assigned', 'self_assessment', 'manager_review', 'finalized', 'acknowledged'];
    private const FINALIZED_STATES = ['finalized', 'acknowledged'];

    /** Allowed lifecycle transitions (server-enforced state machine). */
    private const TRANSITIONS = [
        'drafted'         => ['assigned'],
        'assigned'        => ['drafted', 'self_assessment'],
        'self_assessment' => ['assigned', 'manager_review'],
        'manager_review'  => ['finalized'],
        'finalized'       => [],
        'acknowledged'    => [],
    ];

    // ------------------------------------------------------------------
    // Auth / context helpers
    // ------------------------------------------------------------------

    private static function currentIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }

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

    /** Employee-scoped context for own-review endpoints. */
    private static function ownCtx(): array
    {
        $ctx = self::ctx();
        if (!in_array($ctx['role'], self::OWNER_ROLES, true)) {
            Response::forbidden('Performance reviews are available to employees only.');
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

    /** True when the given review is within the caller's management scope. */
    private static function accessReview(array $p, array $ctx): bool
    {
        if ($ctx['role'] === 'hr') {
            return true;
        }
        if ($ctx['role'] !== 'manager') {
            return false;
        }
        if ((int) $p['reviewer_user_id'] === $ctx['id']) {
            return true;
        }
        if ($ctx['employee_id'] <= 0) {
            return false;
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM employees WHERE id = :eid AND manager_id = :mgr LIMIT 1');
        $stmt->execute([':eid' => (int) $p['employee_id'], ':mgr' => $ctx['employee_id']]);
        return (int) $stmt->fetchColumn() > 0;
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

    /** Fetch the caller's own review (null when absent / not owned). */
    private static function ownRow(int $employeeId, int $reviewId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM performance_reviews WHERE id = :id AND employee_id = :eid LIMIT 1');
        $stmt->execute([':id' => $reviewId, ':eid' => $employeeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ------------------------------------------------------------------
    // Query / shape helpers
    // ------------------------------------------------------------------

    /** Review row joined with employee, period and reviewer (single review). */
    private static function detailRow(int $reviewId): ?array
    {
        $stmt = db()->prepare(
            'SELECT p.*,
                    e.employee_no, e.first_name, e.last_name, e.job_title, e.department_id,
                    rp.name AS period_name, rp.period_type,
                    rp.start_date AS period_start, rp.end_date AS period_end, rp.status AS period_status,
                    rv.username AS reviewer_username
             FROM performance_reviews p
             JOIN employees e ON e.id = p.employee_id
             JOIN review_periods rp ON rp.id = p.period_id
             LEFT JOIN users rv ON rv.id = p.reviewer_user_id
             WHERE p.id = :rid
             LIMIT 1'
        );
        $stmt->execute([':rid' => $reviewId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Linked goals with live employee_goals values left-joined. */
    private static function goalResults(int $reviewId): array
    {
        $stmt = db()->prepare(
            'SELECT rgr.*,
                    g.title AS live_title, g.status AS live_status, g.progress AS live_progress,
                    g.due_date AS live_due_date, g.category AS live_category, g.target AS live_target
             FROM review_goal_results rgr
             LEFT JOIN employee_goals g ON g.id = rgr.goal_id
             WHERE rgr.review_id = :rid
             ORDER BY rgr.id'
        );
        $stmt->execute([':rid' => $reviewId]);
        return $stmt->fetchAll();
    }

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

    private static function decodeSelfAssessment(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        $d = json_decode($json, true);
        return is_array($d) ? $d : null;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    private static function fullName(array $p): string
    {
        return trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
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

    private static function ratingOrError(mixed $v, string $field): int
    {
        $r = self::intOrError($v, $field);
        if ($r < self::RATING_MIN || $r > self::RATING_MAX) {
            Response::validation(
                [$field => $field . ' must be between ' . self::RATING_MIN . ' and ' . self::RATING_MAX . '.'],
                'Validation failed.'
            );
        }
        return $r;
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

    private static function intArrayOrError(mixed $v, string $field): array
    {
        if (!is_array($v)) {
            Response::validation([$field => $field . ' must be a list of goal IDs.'], 'Validation failed.');
        }
        $out = [];
        foreach ($v as $item) {
            $out[] = self::intOrError($item, $field);
        }
        return array_values(array_unique($out));
    }

    private static function dateOrError(mixed $v, string $field): string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            Response::validation([$field => $field . ' must use the YYYY-MM-DD format.'], 'Validation failed.');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        if (!checkdate($m, $d, $y)) {
            Response::validation([$field => $field . ' is not a valid date.'], 'Validation failed.');
        }
        return $v;
    }

    private static function audit(int $reviewId, ?string $from, string $to, string $action, ?int $actor): void
    {
        $stmt = db()->prepare(
            'INSERT INTO performance_review_log (review_id, from_status, to_status, action, actor_user_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$reviewId, $from, $to, $action, $actor]);
    }

    /** Snapshot a live employee_goals row into goal_title/status/progress. */
    private static function snapshotOf(array $goal): array
    {
        return [
            'title'    => $goal['title'],
            'status'   => $goal['status'],
            'progress' => (int) $goal['progress'],
        ];
    }

    // ------------------------------------------------------------------
    // Shapes
    // ------------------------------------------------------------------

    private static function periodShape(array $p): array
    {
        return [
            'id'          => (int) $p['id'],
            'name'        => $p['name'],
            'period_type' => $p['period_type'],
            'start_date'  => $p['start_date'],
            'end_date'    => $p['end_date'],
            'status'      => $p['status'],
        ];
    }

    private static function goalResultShape(array $r, bool $masked): array
    {
        $title = $r['live_title'] ?? $r['goal_title'];
        return [
            'id'                       => (int) $r['id'],
            'goal_id'                  => (int) $r['goal_id'],
            'title'                    => $title,
            'target'                   => $r['live_target'] ?? null,
            'category'                 => $r['live_category'] ?? null,
            'due_date'                 => $r['live_due_date'] ?? null,
            'live_status'              => $r['live_status'] ?? $r['goal_status_snapshot'],
            'live_progress'            => self::intOrNull($r['live_progress'] ?? $r['goal_progress_snapshot']),
            'goal_title_snapshot'      => $r['goal_title'],
            'goal_status_snapshot'     => $r['goal_status_snapshot'],
            'goal_progress_snapshot'   => (int) $r['goal_progress_snapshot'],
            'rating'                   => $masked ? null : self::intOrNull($r['rating']),
            'result_notes'             => $masked ? null : $r['result_notes'],
        ];
    }

    private static function shapeListRow(array $p, array $ctx, bool $admin): array
    {
        $finalized = in_array($p['status'], self::FINALIZED_STATES, true);
        $masked = !$admin && !$finalized;

        $row = [
            'id'                      => (int) $p['id'],
            'status'                  => $p['status'],
            'period'                  => [
                'id'          => (int) ($p['period_id'] ?? $p['period']['id']),
                'name'        => $p['period_name'] ?? null,
                'period_type' => $p['period_type'] ?? null,
                'start_date'  => $p['period_start'] ?? null,
                'end_date'    => $p['period_end'] ?? null,
            ],
            'self_submitted_at'       => $p['self_submitted_at'],
            'acknowledged_at'         => $p['acknowledged_at'],
            'manager_rating'          => $masked ? null : self::intOrNull($p['manager_rating']),
            'final_rating'            => $masked ? null : self::intOrNull($p['final_rating']),
            'final_rating_label'      => ($masked || $p['final_rating'] === null) ? null : $p['final_rating_label'],
            'can_submit_self'         => $p['status'] === 'self_assessment' && $p['self_submitted_at'] === null,
            'can_acknowledge'         => $p['status'] === 'finalized',
        ];

        if ($admin) {
            $row['employee'] = [
                'id'            => (int) $p['employee_id'],
                'employee_no'   => $p['employee_no'] ?? null,
                'name'          => self::fullName($p),
                'job_title'     => $p['job_title'] ?? null,
                'department_id' => self::intOrNull($p['department_id'] ?? null),
            ];
            $row['reviewer_user_id'] = self::intOrNull($p['reviewer_user_id'] ?? null);
            $row['reviewer_username'] = $p['reviewer_username'] ?? null;
        }
        return $row;
    }

    private static function shapeDetail(array $p, array $goalResults, array $ctx, bool $admin): array
    {
        $finalized = in_array($p['status'], self::FINALIZED_STATES, true);
        $masked = !$admin && !$finalized;

        $row = self::shapeListRow($p, $ctx, $admin);
        $row['manager_feedback']   = $masked ? null : $p['manager_feedback'];
        $row['self_assessment']    = self::decodeSelfAssessment($p['self_assessment']);
        $row['acknowledge_note']   = $p['acknowledge_note'];
        $row['goal_results']       = array_map(
            static fn (array $r) => self::goalResultShape($r, $masked),
            $goalResults
        );
        $row['ratings_hidden']     = $masked;
        $row['updated_at']         = $p['updated_at'];
        return $row;
    }

    // ------------------------------------------------------------------
    // EMPLOYEE ENDPOINTS
    // ------------------------------------------------------------------

    /** GET employee/performance — list the caller's own reviews. */
    public static function index(): never
    {
        $ctx = self::ownCtx();
        $stmt = db()->prepare(
            'SELECT p.*, rp.name AS period_name, rp.period_type,
                    rp.start_date AS period_start, rp.end_date AS period_end,
                    rv.username AS reviewer_username
             FROM performance_reviews p
             JOIN review_periods rp ON rp.id = p.period_id
             LEFT JOIN users rv ON rv.id = p.reviewer_user_id
             WHERE p.employee_id = :eid
             ORDER BY rp.end_date DESC, p.id DESC'
        );
        $stmt->execute([':eid' => $ctx['employee_id']]);
        $items = array_map(
            static fn (array $p) => self::shapeListRow($p, $ctx, false),
            $stmt->fetchAll()
        );
        Response::list($items, 'Performance reviews retrieved successfully.', 1, count($items), count($items));
    }

    /** GET employee/performance/{id} — own review detail. */
    public static function show(int $id): never
    {
        $ctx = self::ownCtx();
        $p = self::detailRow($id);
        if (!$p || (int) $p['employee_id'] !== $ctx['employee_id']) {
            Response::notFound('Review not found.');
        }
        Response::item(
            self::shapeDetail($p, self::goalResults($id), $ctx, false),
            'Performance review retrieved successfully.'
        );
    }

    private static function selfAssessmentPayload(): array
    {
        $body = api_body();
        $out = [];
        $fields = ['strengths' => self::MAX_SELF_FIELD, 'improvements' => self::MAX_SELF_FIELD, 'comments' => self::MAX_COMMENTS];
        foreach ($fields as $field => $max) {
            if (array_key_exists($field, $body) && $body[$field] !== null && $body[$field] !== '') {
                $out[$field] = self::textOrError($body[$field], $field, $max);
            }
        }
        if (!$out) {
            Response::validation(
                ['self_assessment' => 'Provide at least one of strengths, improvements, or comments.'],
                'Validation failed.'
            );
        }
        return $out;
    }

    private static function assertSelfAssessmentOpen(array $p): void
    {
        if ($p['status'] !== 'self_assessment') {
            Response::validation(
                ['self_assessment' => 'Self-assessment is only available while the review is in the Self Assessment stage.'],
                'Self-assessment is not open.'
            );
        }
        if ($p['self_submitted_at'] !== null) {
            Response::validation(
                ['self_assessment' => 'Self-assessment already submitted and cannot be changed.'],
                'Self-assessment already submitted.'
            );
        }
    }

    /** PATCH employee/performance/{id}/self-assessment — save a draft (no transition). */
    public static function saveSelfAssessmentDraft(int $id): never
    {
        $ctx = self::ownCtx();
        $p = self::ownRow($ctx['employee_id'], $id);
        if (!$p) {
            Response::notFound('Review not found.');
        }
        self::assertSelfAssessmentOpen($p);
        $data = self::selfAssessmentPayload();

        $stmt = db()->prepare('UPDATE performance_reviews SET self_assessment = :sa WHERE id = :id');
        $stmt->execute([':sa' => json_encode($data, JSON_UNESCAPED_UNICODE), ':id' => $id]);
        self::audit($id, $p['status'], $p['status'], 'self_assessment_draft', $ctx['id']);

        $fresh = self::detailRow($id);
        Response::item(self::shapeDetail($fresh, self::goalResults($id), $ctx, false), 'Self-assessment draft saved.');
    }

    /** POST employee/performance/{id}/self-assessment — submit (→ manager_review). */
    public static function submitSelfAssessment(int $id): never
    {
        if (!RateLimit::attempt('perf_self:' . self::currentIp(), 30, 900)) {
            Response::tooManyRequests('Too many self-assessment attempts. Please try again later.');
        }

        $ctx = self::ownCtx();
        $p = self::ownRow($ctx['employee_id'], $id);
        if (!$p) {
            Response::notFound('Review not found.');
        }
        self::assertSelfAssessmentOpen($p);
        $data = self::selfAssessmentPayload();
        $now = date('Y-m-d H:i:s');

        $stmt = db()->prepare(
            'UPDATE performance_reviews
             SET self_assessment = :sa, self_submitted_at = :now, status = :to
             WHERE id = :id'
        );
        $stmt->execute([
            ':sa'  => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':now' => $now,
            ':to'  => 'manager_review',
            ':id'  => $id,
        ]);
        self::audit($id, 'self_assessment', 'manager_review', 'self_assessment_submitted', $ctx['id']);

        $fresh = self::detailRow($id);
        Response::item(self::shapeDetail($fresh, self::goalResults($id), $ctx, false), 'Self-assessment submitted.');
    }

    /** POST employee/performance/{id}/acknowledge — acknowledge a finalized review. */
    public static function acknowledge(int $id): never
    {
        if (!RateLimit::attempt('perf_ack:' . self::currentIp(), 15, 900)) {
            Response::tooManyRequests('Too many acknowledgement attempts. Please try again later.');
        }

        $ctx = self::ownCtx();
        $p = self::ownRow($ctx['employee_id'], $id);
        if (!$p) {
            Response::notFound('Review not found.');
        }
        if ($p['status'] !== 'finalized') {
            Response::validation(
                ['acknowledgement' => 'A review can only be acknowledged after it has been finalized.'],
                'Acknowledgement is not available yet.'
            );
        }

        $body = api_body();
        $note = null;
        if (array_key_exists('acknowledge_note', $body)) {
            $note = self::textOrError($body['acknowledge_note'], 'acknowledge_note', self::MAX_NOTE_LENGTH);
        }
        $now = date('Y-m-d H:i:s');

        $stmt = db()->prepare(
            'UPDATE performance_reviews
             SET status = :to, acknowledged_at = :now, acknowledge_note = :note
             WHERE id = :id'
        );
        $stmt->execute([
            ':to'   => 'acknowledged',
            ':now'  => $now,
            ':note' => $note,
            ':id'   => $id,
        ]);
        self::audit($id, 'finalized', 'acknowledged', 'acknowledged', $ctx['id']);

        $fresh = self::detailRow($id);
        Response::item(self::shapeDetail($fresh, self::goalResults($id), $ctx, false), 'Review acknowledged.');
    }

    // ------------------------------------------------------------------
    // ADMIN / MANAGER ENDPOINTS
    // ------------------------------------------------------------------

    /** GET admin/performance/periods — list review periods. */
    public static function periods(): never
    {
        self::adminCtx();
        $rows = db()->query('SELECT * FROM review_periods ORDER BY start_date DESC, id DESC')->fetchAll();
        $items = array_map([self::class, 'periodShape'], $rows);
        Response::list($items, 'Review periods retrieved successfully.', 1, count($items), count($items));
    }

    /** POST admin/performance/periods — create a review period (HR only). */
    public static function createPeriod(): never
    {
        $ctx = self::adminCtx();
        if ($ctx['role'] !== 'hr') {
            Response::forbidden('Only HR can create review periods.');
        }

        $body = api_body();
        $fields = ['name' => null, 'period_type' => null, 'start_date' => null, 'end_date' => null, 'status' => 'planned'];
        foreach ($fields as $f => $default) {
            if (!array_key_exists($f, $body)) {
                if ($default !== null) {
                    $body[$f] = $default;
                } else {
                    Response::validation([$f => $f . ' is required.'], 'Validation failed.');
                }
            }
        }

        $name = self::textOrError($body['name'], 'name', 120);
        $type = strtolower(trim((string) $body['period_type']));
        if (!in_array($type, self::PERIOD_TYPES, true)) {
            Response::validation(['period_type' => 'period_type must be one of: ' . implode(', ', self::PERIOD_TYPES) . '.'], 'Validation failed.');
        }
        $start = self::dateOrError($body['start_date'], 'start_date');
        $end = self::dateOrError($body['end_date'], 'end_date');
        if (strtotime($end) < strtotime($start)) {
            Response::validation(['end_date' => 'end_date must not be before start_date.'], 'Validation failed.');
        }
        $status = strtolower(trim((string) $body['status']));
        if (!in_array($status, self::PERIOD_STATUSES, true)) {
            Response::validation(['status' => 'status must be one of: ' . implode(', ', self::PERIOD_STATUSES) . '.'], 'Validation failed.');
        }

        $stmt = db()->prepare(
            'INSERT INTO review_periods (name, period_type, start_date, end_date, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $type, $start, $end, $status, $ctx['id']]);

        $stmt2 = db()->prepare('SELECT * FROM review_periods WHERE id = LAST_INSERT_ID() LIMIT 1');
        $stmt2->execute();
        $row = $stmt2->fetch();
        Response::created(self::periodShape($row), 'Review period created.');
    }

    /** GET admin/performance/options?employee_id= — candidate goals + rating options. */
    public static function options(): never
    {
        $ctx = self::adminCtx();
        $employeeId = (int) (api_query('employee_id') ?? 0);
        if ($employeeId <= 0) {
            Response::validation(['employee_id' => 'employee_id query parameter is required.'], 'Validation failed.');
        }
        self::checkEmployeeScope($employeeId, $ctx);

        $goalStmt = db()->prepare(
            'SELECT id, title, category, status, progress, due_date, target
             FROM employee_goals WHERE employee_id = :eid ORDER BY due_date, id'
        );
        $goalStmt->execute([':eid' => $employeeId]);
        $goals = array_map(static function (array $g): array {
            return [
                'id'       => (int) $g['id'],
                'title'    => $g['title'],
                'category' => $g['category'],
                'status'   => $g['status'],
                'progress' => (int) $g['progress'],
                'due_date' => $g['due_date'],
                'target'   => $g['target'],
            ];
        }, $goalStmt->fetchAll());

        $ratings = array_map(static function (array $r): array {
            return [
                'scale_code' => $r['scale_code'],
                'value'      => (int) $r['value'],
                'label'      => $r['label'],
            ];
        }, db()->query('SELECT scale_code, value, label FROM rating_options ORDER BY scale_code, sort_order')->fetchAll());

        Response::item(['employee_id' => $employeeId, 'goals' => $goals, 'ratings' => $ratings], 'Options retrieved successfully.');
    }

    /** GET admin/performance/reviews — list reviews within the manager/HR scope. */
    public static function adminIndex(): never
    {
        $ctx = self::adminCtx();

        $where = [];
        $params = [];
        if ($ctx['role'] !== 'hr') {
            $where[] = '(e.manager_id = :mgr_emp OR p.reviewer_user_id = :mgr_usr)';
            $params[':mgr_emp'] = $ctx['employee_id'];
            $params[':mgr_usr'] = $ctx['id'];
        }

        $periodId = api_query('period_id');
        $status = api_query('status');
        $departmentId = api_query('department_id');
        $employeeId = api_query('employee_id');
        if ($periodId !== null) {
            $where[] = 'p.period_id = :period_id';
            $params[':period_id'] = self::intOrError($periodId, 'period_id');
        }
        if ($status !== null) {
            $statusStr = strtolower(trim((string) $status));
            if (!in_array($statusStr, self::REVIEW_STATUSES, true)) {
                Response::validation(['status' => 'status must be one of: ' . implode(', ', self::REVIEW_STATUSES) . '.'], 'Validation failed.');
            }
            $where[] = 'p.status = :status';
            $params[':status'] = $statusStr;
        }
        if ($departmentId !== null) {
            $where[] = 'e.department_id = :dept_id';
            $params[':dept_id'] = self::intOrError($departmentId, 'department_id');
        }
        if ($employeeId !== null) {
            $where[] = 'p.employee_id = :employee_id';
            $params[':employee_id'] = self::intOrError($employeeId, 'employee_id');
        }

        $sql = 'SELECT p.*, e.employee_no, e.first_name, e.last_name, e.job_title, e.department_id,
                       rp.name AS period_name, rp.period_type, rp.start_date AS period_start, rp.end_date AS period_end,
                       rv.username AS reviewer_username
                FROM performance_reviews p
                JOIN employees e ON e.id = p.employee_id
                JOIN review_periods rp ON rp.id = p.period_id
                LEFT JOIN users rv ON rv.id = p.reviewer_user_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY rp.end_date DESC, p.id DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $items = array_map(
            static fn (array $p) => self::shapeListRow($p, $ctx, true),
            $stmt->fetchAll()
        );
        Response::list($items, 'Performance reviews retrieved successfully.', 1, count($items), count($items));
    }

    /**
     * POST admin/performance/reviews — create + assign (optionally link goals).
     * Body: { employee_id, period_id, reviewer_user_id?, goal_ids? }
     */
    public static function store(): never
    {
        $ctx = self::adminCtx();
        $body = api_body();

        $employeeId = self::intOrError($body['employee_id'] ?? null, 'employee_id');
        $periodId = self::intOrError($body['period_id'] ?? null, 'period_id');

        $empStmt = db()->prepare('SELECT id FROM employees WHERE id = :id LIMIT 1');
        $empStmt->execute([':id' => $employeeId]);
        if (!$empStmt->fetch()) {
            Response::notFound('Employee not found.');
        }
        self::checkEmployeeScope($employeeId, $ctx);

        $periodStmt = db()->prepare('SELECT id, status FROM review_periods WHERE id = :id LIMIT 1');
        $periodStmt->execute([':id' => $periodId]);
        $period = $periodStmt->fetch();
        if (!$period) {
            Response::notFound('Review period not found.');
        }
        if ($period['status'] === 'closed') {
            Response::validation(['period_id' => 'Cannot create a review for a closed period.'], 'Validation failed.');
        }

        $reviewerId = null;
        if (array_key_exists('reviewer_user_id', $body) && $body['reviewer_user_id'] !== null && $body['reviewer_user_id'] !== '') {
            $reviewerId = self::intOrError($body['reviewer_user_id'], 'reviewer_user_id');
            $rvStmt = db()->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $rvStmt->execute([':id' => $reviewerId]);
            if (!$rvStmt->fetch()) {
                Response::validation(['reviewer_user_id' => 'Reviewer user does not exist or is inactive.'], 'Validation failed.');
            }
        }

        $dupStmt = db()->prepare('SELECT id FROM performance_reviews WHERE employee_id = :eid AND period_id = :pid LIMIT 1');
        $dupStmt->execute([':eid' => $employeeId, ':pid' => $periodId]);
        if ($dupStmt->fetch()) {
            Response::conflict('A performance review already exists for this employee in the selected period.');
        }

        $goalIds = [];
        if (array_key_exists('goal_ids', $body) && $body['goal_ids'] !== null && $body['goal_ids'] !== []) {
            $goalIds = self::intArrayOrError($body['goal_ids'], 'goal_ids');
        }

        $reviewStmt = db()->prepare(
            'INSERT INTO performance_reviews (employee_id, period_id, reviewer_user_id, status)
             VALUES (?, ?, ?, ?)'
        );
        $reviewStmt->execute([$employeeId, $periodId, $reviewerId, 'drafted']);
        $reviewId = (int) db()->lastInsertId();

        $errors = [];
        $snapshots = [];
        if ($goalIds) {
            $place = implode(',', array_fill(0, count($goalIds), '?'));
            $s = db()->prepare("SELECT id, title, status, progress FROM employee_goals WHERE id IN ($place)");
            $s->execute($goalIds);
            foreach ($s->fetchAll() as $goal) {
                if ($goal['status'] === 'cancelled') {
                    continue;
                }
                $snapshots[(int) $goal['id']] = self::snapshotOf($goal);
            }
            $ins = db()->prepare(
                'INSERT INTO review_goal_results (review_id, goal_id, goal_title, goal_status_snapshot, goal_progress_snapshot)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($goalIds as $gid) {
                $own = db()->prepare('SELECT COUNT(*) FROM employee_goals WHERE id = :gid AND employee_id = :eid LIMIT 1');
                $own->execute([':gid' => $gid, ':eid' => $employeeId]);
                if ((int) $own->fetchColumn() <= 0) {
                    $errors['goal_ids'] = "Goal $gid does not belong to this employee.";
                    continue;
                }
                $snap = $snapshots[$gid] ?? null;
                if ($snap === null) {
                    continue; // cancelled goals are skipped
                }
                $ins->execute([$reviewId, $gid, $snap['title'], $snap['status'], $snap['progress']]);
            }
        }
        if ($errors) {
            // Roll back the empty created review so no half-assigned review persists.
            db()->prepare('DELETE FROM performance_reviews WHERE id = ?')->execute([$reviewId]);
            Response::validation($errors, 'Validation failed.');
        }

        self::audit($reviewId, null, 'drafted', 'review_created', $ctx['id']);
        $fresh = self::detailRow($reviewId);
        Response::created(self::shapeDetail($fresh, self::goalResults($reviewId), $ctx, true), 'Performance review created.');
    }

    /** Fetch + scope-guard one review for an admin caller. */
    private static function adminReview(int $id, array $ctx): array
    {
        $p = self::detailRow($id);
        if (!$p) {
            Response::notFound('Review not found.');
        }
        if (!self::accessReview($p, $ctx)) {
            Response::notFound('Review not found.');
        }
        return $p;
    }

    private static function assertNotFinalized(array $p): void
    {
        if (in_array($p['status'], self::FINALIZED_STATES, true)) {
            Response::validation(
                ['review' => 'This review is ' . $p['status'] . ' and can no longer be modified.'],
                'Finalized reviews are locked.'
            );
        }
    }

    /** GET admin/performance/reviews/{id} — review detail. */
    public static function adminShow(int $id): never
    {
        $ctx = self::adminCtx();
        $p = self::adminReview($id, $ctx);
        Response::item(self::shapeDetail($p, self::goalResults($id), $ctx, true), 'Performance review retrieved successfully.');
    }

    /** Compute + persist the overall rating at finalize time. */
    private static function computeFinalRating(int $reviewId, int $managerRating): int
    {
        $avgStmt = db()->prepare(
            'SELECT AVG(rating) FROM review_goal_results WHERE review_id = :rid AND rating IS NOT NULL'
        );
        $avgStmt->execute([':rid' => $reviewId]);
        $goalAvg = $avgStmt->fetchColumn();

        $final = $managerRating;
        if ($goalAvg !== null && $goalAvg !== false) {
            $pStmt = db()->prepare('SELECT weight_config FROM performance_reviews WHERE id = :rid LIMIT 1');
            $pStmt->execute([':rid' => $reviewId]);
            $w = json_decode((string) $pStmt->fetchColumn(), true);
            if (!is_array($w)) {
                $w = self::DEFAULT_WEIGHTS;
            }
            $goalW = (float) ($w['goal'] ?? self::DEFAULT_WEIGHTS['goal']);
            $mgrW = (float) ($w['manager'] ?? self::DEFAULT_WEIGHTS['manager']);
            $total = $goalW + $mgrW;
            $final = (int) round(((float) $goalAvg * $goalW + (float) $managerRating * $mgrW) / max(1.0, $total));
        }
        return max(self::RATING_MIN, min(self::RATING_MAX, $final));
    }

    /** PATCH admin/performance/reviews/{id} — transition + optional reviewer/rating/feedback/goals. */
    public static function adminUpdate(int $id): never
    {
        $ctx = self::adminCtx();
        $p = self::adminReview($id, $ctx);

        $body = api_body();

        // --- reviewer_user_id (validated against users) ---
        $reviewerId = null;
        if (array_key_exists('reviewer_user_id', $body) && $body['reviewer_user_id'] !== null && $body['reviewer_user_id'] !== '') {
            $reviewerId = self::intOrError($body['reviewer_user_id'], 'reviewer_user_id');
            $rvStmt = db()->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $rvStmt->execute([':id' => $reviewerId]);
            if (!$rvStmt->fetch()) {
                Response::validation(['reviewer_user_id' => 'Reviewer user does not exist or is inactive.'], 'Validation failed.');
            }
        }

        // --- manager_rating / manager_feedback ---
        $managerRating = null;
        if (array_key_exists('manager_rating', $body)) {
            $managerRating = self::ratingOrError($body['manager_rating'], 'manager_rating');
        }
        $managerFeedback = null;
        if (array_key_exists('manager_feedback', $body)) {
            $managerFeedback = self::textOrError($body['manager_feedback'], 'manager_feedback', self::MAX_FEEDBACK_LENGTH);
        }

        // --- status transition ---
        $newStatus = null;
        if (array_key_exists('status', $body) && $body['status'] !== null && $body['status'] !== '') {
            $newStatus = strtolower(trim((string) $body['status']));
            if (!in_array($newStatus, self::REVIEW_STATUSES, true)) {
                Response::validation(['status' => 'status must be one of: ' . implode(', ', self::REVIEW_STATUSES) . '.'], 'Validation failed.');
            }
            if (!in_array($newStatus, self::TRANSITIONS[$p['status']] ?? [], true)) {
                $allowed = self::TRANSITIONS[$p['status']] ?? [];
                $msg = $allowed
                    ? 'Allowed next status for ' . $p['status'] . ': ' . implode(', ', $allowed) . '.'
                    : $p['status'] . ' is a final state and cannot transition.'
                    ;
                Response::validation(['status' => $msg], 'Illegal status transition.');
            }
        }

        // --- add_goal_ids (attach more goals; ownership + snapshot) ---
        $addGoalIds = [];
        if (array_key_exists('add_goal_ids', $body) && $body['add_goal_ids'] !== null && $body['add_goal_ids'] !== []) {
            $addGoalIds = self::intArrayOrError($body['add_goal_ids'], 'add_goal_ids');
        }

        $sets = [];
        $params = [':id' => $id];
        if ($reviewerId !== null) {
            $sets[] = 'reviewer_user_id = :reviewer';
            $params[':reviewer'] = $reviewerId;
        }
        if ($managerRating !== null) {
            $sets[] = 'manager_rating = :mgr_rating';
            $params[':mgr_rating'] = $managerRating;
        }
        if ($managerFeedback !== null) {
            $sets[] = 'manager_feedback = :mgr_feedback';
            $params[':mgr_feedback'] = $managerFeedback;
        }

        if ($sets) {
            db()->prepare('UPDATE performance_reviews SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        }

        // Attach goals before finalizing.
        if ($addGoalIds) {
            self::assertNotFinalized($p);
            foreach ($addGoalIds as $gid) {
                $own = db()->prepare('SELECT id, title, status, progress FROM employee_goals WHERE id = :gid AND employee_id = :eid LIMIT 1');
                $own->execute([':gid' => $gid, ':eid' => (int) $p['employee_id']]);
                $goal = $own->fetch();
                if (!$goal || $goal['status'] === 'cancelled') {
                    Response::validation(['add_goal_ids' => "Goal $gid does not belong to this employee or is cancelled."], 'Validation failed.');
                }
                $snap = self::snapshotOf($goal);
                db()->prepare(
                    'INSERT INTO review_goal_results (review_id, goal_id, goal_title, goal_status_snapshot, goal_progress_snapshot)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       goal_title = VALUES(goal_title),
                       goal_status_snapshot = VALUES(goal_status_snapshot),
                       goal_progress_snapshot = VALUES(goal_progress_snapshot)'
                )->execute([$id, $gid, $snap['title'], $snap['status'], $snap['progress']]);
            }
        }

        // Finalize: compute the overall rating + label, then lock.
        if ($newStatus === 'finalized') {
            self::assertNotFinalized($p);
            $fresh = db()->prepare('SELECT * FROM performance_reviews WHERE id = :id LIMIT 1');
            $fresh->execute([':id' => $id]);
            $after = $fresh->fetch();
            $mgr = (int) ($after['manager_rating'] ?? ($managerRating ?? 0));
            if ($mgr < self::RATING_MIN || $mgr > self::RATING_MAX) {
                Response::validation(
                    ['manager_rating' => 'A manager rating (1-5) is required before the review can be finalized.'],
                    'Manager rating required.'
                );
            }
            $final = self::computeFinalRating($id, $mgr);
            $label = self::labelFor('overall', $final, null);
            $weightConfig = $after['weight_config'];
            if ($weightConfig === null) {
                $weightConfig = json_encode(self::DEFAULT_WEIGHTS);
            }
            db()->prepare(
                'UPDATE performance_reviews
                 SET status = :to, final_rating = :fr, final_rating_label = :label, weight_config = :wc
                 WHERE id = :id'
            )->execute([
                ':to'     => 'finalized',
                ':fr'     => $final,
                ':label'  => $label,
                ':wc'     => $weightConfig,
                ':id'     => $id,
            ]);
            $p = self::detailRow($id);
            self::audit($id, 'manager_review', 'finalized', 'review_finalized', $ctx['id']);
            Response::item(self::shapeDetail($p, self::goalResults($id), $ctx, true), 'Review finalized.');
        }

        if ($newStatus !== null && $newStatus !== 'finalized') {
            db()->prepare('UPDATE performance_reviews SET status = :to WHERE id = :id')
                 ->execute([':to' => $newStatus, ':id' => $id]);
            self::audit($id, $p['status'], $newStatus, 'status_changed', $ctx['id']);
            $p = self::detailRow($id);
        }

        Response::item(self::shapeDetail($p, self::goalResults($id), $ctx, true), 'Review updated.');
    }

    /** PUT admin/performance/reviews/{id}/goal-results — score linked goals. */
    public static function scoreGoals(int $id): never
    {
        $ctx = self::adminCtx();
        $p = self::adminReview($id, $ctx);
        self::assertNotFinalized($p);

        $body = api_body();
        $results = $body['results'] ?? null;
        if (!is_array($results) || $results === []) {
            Response::validation(['results' => 'Provide a non-empty results list: [{goal_id, rating, notes?}].'], 'Validation failed.');
        }

        $errors = [];
        $updates = [];
        foreach ($results as $i => $item) {
            if (!is_array($item)) {
                $errors["results.$i"] = 'Each result must be an object {goal_id, rating, notes?}.';
                continue;
            }
            $goalId = self::intOrError($item['goal_id'] ?? null, "results.$i.goal_id");
            $rating = self::ratingOrError($item['rating'] ?? null, "results.$i.rating");
            $link = db()->prepare(
                'SELECT rgr.id, rgr.goal_id, rgr.review_id, g.title, g.status, g.progress
                 FROM review_goal_results rgr
                 JOIN employee_goals g ON g.id = rgr.goal_id
                 WHERE rgr.review_id = :rid AND rgr.goal_id = :gid LIMIT 1'
            );
            $link->execute([':rid' => $id, ':gid' => $goalId]);
            $linked = $link->fetch();
            if (!$linked) {
                $errors["results.$i.goal_id"] = "Goal $goalId is not linked to this review.";
                continue;
            }
            $notes = null;
            if (array_key_exists('notes', $item) && $item['notes'] !== null) {
                $notes = self::textOrError($item['notes'], "results.$i.notes", self::MAX_NOTE_LENGTH);
            }
            $updates[] = [$goalId, $rating, $notes, (int) $linked['id']];
        }
        if ($errors) {
            Response::validation($errors, 'Validation failed.');
        }

        $stmt = db()->prepare(
            'UPDATE review_goal_results
             SET rating = ?, result_notes = ?, goal_title = ?, goal_status_snapshot = ?, goal_progress_snapshot = ?
             WHERE id = ?'
        );
        foreach ($updates as [$goalId, $rating, $notes, $rowId]) {
            $g = db()->prepare('SELECT title, status, progress FROM employee_goals WHERE id = ? LIMIT 1');
            $g->execute([$goalId]);
            $live = $g->fetch();
            $snap = self::snapshotOf($live);
            $stmt->execute([$rating, $notes, $snap['title'], $snap['status'], $snap['progress'], $rowId]);
        }
        self::audit($id, $p['status'], $p['status'], 'goal_results_scored', $ctx['id']);
        $fresh = self::detailRow($id);
        Response::item(self::shapeDetail($fresh, self::goalResults($id), $ctx, true), 'Goal results saved.');
    }

    /** PUT admin/performance/reviews/{id}/manager-feedback — feedback + manager rating. */
    public static function managerFeedback(int $id): never
    {
        $ctx = self::adminCtx();
        $p = self::adminReview($id, $ctx);
        self::assertNotFinalized($p);

        $body = api_body();
        $hasFeedback = array_key_exists('manager_feedback', $body);
        $hasRating = array_key_exists('manager_rating', $body);

        if (!$hasFeedback && !$hasRating) {
            Response::validation(
                ['manager_feedback' => 'Provide manager_feedback and/or manager_rating.'],
                'Nothing to update.'
            );
        }

        $sets = [];
        $params = [':id' => $id];
        if ($hasFeedback) {
            $sets[] = 'manager_feedback = :fb';
            $params[':fb'] = self::textOrError($body['manager_feedback'], 'manager_feedback', self::MAX_FEEDBACK_LENGTH);
        }
        if ($hasRating) {
            $sets[] = 'manager_rating = :mgr';
            $params[':mgr'] = self::ratingOrError($body['manager_rating'], 'manager_rating');
        }

        db()->prepare('UPDATE performance_reviews SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        self::audit($id, $p['status'], $p['status'], 'manager_feedback_updated', $ctx['id']);

        $fresh = self::detailRow($id);
        Response::item(self::shapeDetail($fresh, self::goalResults($id), $ctx, true), 'Manager feedback saved.');
    }
}