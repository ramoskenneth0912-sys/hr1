<?php
require_once __DIR__ . '/environment.php';

require_once __DIR__ . '/session.php';

require_once __DIR__ . '/../config/database.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    // If headers have already been sent (e.g. a page emitted output before the
    // redirect site), fall back to an HTML/JS redirect instead of raising a
    // "Cannot modify header information" warning. The common fast path below
    // (no output yet) is unchanged.
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    $url = htmlspecialchars($url ?? '', ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<meta http-equiv="refresh" content="0;url=' . $url . '">'
        . '<script>location.replace(' . json_encode($url) . ');</script>'
        . '<noscript><a href="' . $url . '">Continue</a></noscript>'
        . '</head><body></body></html>';
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function generateCode(string $prefix, string $table, string $column): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) ||
        !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column) ||
        !preg_match('/^[a-zA-Z0-9]+$/', $prefix)) {
        throw new InvalidArgumentException('Invalid identifier in generateCode()');
    }

    $offset = strlen($prefix) + 1;
    $likePrefix = addcslashes($prefix, '%_');
    $sql = "SELECT MAX(CAST(SUBSTRING(`$column`, $offset) AS UNSIGNED)) AS max_num
            FROM `$table` WHERE `$column` LIKE :like";
    $stmt = db()->prepare($sql);
    $stmt->execute([':like' => $likePrefix . '%']);
    $row = $stmt->fetch();
    $next = ((int) ($row['max_num'] ?? 0)) + 1;
    return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
}

function getDepartments(): array
{
    return db()->query('SELECT id, code, name FROM departments ORDER BY name')->fetchAll();
}

function getEmployees(bool $activeOnly = true): array
{
    $sql = 'SELECT e.*, d.name AS department_name FROM employees e LEFT JOIN departments d ON e.department_id = d.id';
    if ($activeOnly) {
        $sql .= " WHERE e.status = 'active'";
    }
    $sql .= ' ORDER BY e.last_name, e.first_name';
    return db()->query($sql)->fetchAll();
}

function getApplicants(): array
{
    return db()->query(
        'SELECT a.*, d.name AS department_name FROM applicants a
         LEFT JOIN departments d ON a.department_id = d.id
         ORDER BY a.applied_date DESC'
    )->fetchAll();
}

function formatDate(?string $date): string
{
    if (!$date) {
        return '—';
    }
    return date('M d, Y', strtotime($date));
}

/**
 * True if the string is a valid date in strict Y-m-d format.
 */
function isValidDateString(string $d): bool
{
    return $d !== ''
        && ($dt = DateTime::createFromFormat('Y-m-d', $d)) !== false
        && $dt->format('Y-m-d') === $d;
}

/**
 * Server-side future-date validation for scheduled/future dates.
 * A date must be strictly later than today (today itself is rejected).
 *
 * @return string|null Error message, or null when the date is valid/empty/optional.
 */
function validateFutureDate(?string $d): ?string
{
    $d = trim((string) $d);
    if ($d === '') {
        return null;
    }
    if (!isValidDateString($d)) {
        return 'Invalid date format.';
    }
    if ($d <= date('Y-m-d')) {
        return 'Date must be a future date.';
    }
    return null;
}

/**
 * Server-side validation for a date of birth. Past and today are allowed;
 * a future date is rejected ("a person cannot be born in the future").
 *
 * @return string|null Error message, or null when valid/empty/optional.
 */
function validateDateOfBirth(?string $d): ?string
{
    $d = trim((string) $d);
    if ($d === '') {
        return null;
    }
    if (!isValidDateString($d)) {
        return 'Invalid date format.';
    }
    if ($d > date('Y-m-d')) {
        return 'Date of birth cannot be in the future.';
    }
    return null;
}

/**
 * Server-side date-range validation for start/end pairs.
 * Enforces End >= Start ("End date cannot be earlier than the start date.").
 * When $futureOnly is true, both dates must also be strictly future dates.
 *
 * @return string[] Human-readable error messages (empty = valid).
 */
function validateDateRange(?string $start, ?string $end, bool $futureOnly = false): array
{
    $errors = [];
    $start = trim((string) $start);
    $end   = trim((string) $end);

    if ($start !== '' && !isValidDateString($start)) {
        $errors[] = 'Start date is invalid.';
    }
    if ($end !== '' && !isValidDateString($end)) {
        $errors[] = 'End date is invalid.';
    }

    if ($futureOnly) {
        if ($err = validateFutureDate($start)) {
            $errors[] = 'Start date: ' . $err;
        }
        if ($err = validateFutureDate($end)) {
            $errors[] = 'End date: ' . $err;
        }
    }

    if (isValidDateString($start) && isValidDateString($end) && $end < $start) {
        $errors[] = 'End date cannot be earlier than the start date.';
    }

    return $errors;
}

function statusBadge(string $status): string
{
    $colors = [
        'new' => 'badge-new',
        'screening' => 'badge-info',
        'shortlisted' => 'badge-primary',
        'accepted' => 'badge-success',
        'passed_screening' => 'badge-success',
        'interview' => 'badge-warning',
        'offered' => 'badge-primary',
        'hired' => 'badge-success',
        'rejected' => 'badge-danger',
        'open' => 'badge-success',
        'closed' => 'badge-danger',
        'inactive' => 'badge-secondary',
        'pending' => 'badge-warning',
        'in_progress' => 'badge-info',
        'completed' => 'badge-success',
        'passed' => 'badge-success',
        'failed' => 'badge-danger',
        'rescheduled' => 'badge-info',
        'approved' => 'badge-success',
        'cancelled' => 'badge-secondary',
        'active' => 'badge-success',
        'on_leave' => 'badge-warning',
        'terminated' => 'badge-danger',
        'resigned' => 'badge-secondary',
        'verified' => 'badge-success',
        'orientation_scheduled' => 'badge-info',
        'orientation_completed' => 'badge-primary',
        'documents_submitted' => 'badge-warning',
        'documents_verified' => 'badge-success',
        'account_created' => 'badge-primary',
        'onboarding_completed' => 'badge-success',
    ];
    $class = $colors[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $class . '">' . e(ucfirst(str_replace('_', ' ', $status))) . '</span>';
}

/** Human-readable label for an application status enum value. */
function applicationStatuses(): array
{
    return [
        'new' => 'Pending',
        'screening' => 'Under Review',
        'shortlisted' => 'Shortlisted',
        'accepted' => 'Accepted',
        'passed_screening' => 'Passed Screening',
        'interview' => 'Interview',
        'offered' => 'Offered',
        'hired' => 'Hired',
        'rejected' => 'Rejected',
    ];
}

/**
 * Create a notification for one user account. Fails silently —
 * a notification must never break an HR workflow.
 */
function notifyUser(?int $userId, string $title, string $message, ?string $link = null): void
{
    if (!$userId) {
        return;
    }
    try {
        db()->prepare(
            'INSERT INTO notifications (user_id, title, message, link) VALUES (?,?,?,?)'
        )->execute([
            $userId,
            mb_substr($title, 0, 150),
            mb_substr($message, 0, 500),
            $link !== null ? mb_substr($link, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('notifyUser failed: ' . $e->getMessage());
    }
}

/**
 * Notify every active HR/Admin account that a new applicant submitted an
 * application together with a resume/CV. Uses the SAME role set as
 * requireHRorManager() ('hr' and 'manager'). The bell link points at the
 * applicant detail page, which re-enforces server-side authorization.
 * Fails silently — a notification must never break the submission flow.
 */
function notifyHRofNewApplicant(int $applicantId, string $applicantName, string $position): void
{
    $applicantName = trim($applicantName);
    $position      = trim($position);
    $applicantName = $applicantName !== '' ? $applicantName : 'An applicant';
    $position      = $position !== '' ? $position : 'a position';

    $link = BASE_URL . '/modules/applicants/view.php?id=' . $applicantId;

    try {
        $stmt = db()->prepare('SELECT id FROM users WHERE role IN (?, ?) AND is_active = 1');
        $stmt->execute(['hr', 'manager']);
        $hrUsers = $stmt->fetchAll();

        foreach ($hrUsers as $hr) {
            notifyUser(
                (int) $hr['id'],
                'New Applicant',
                "{$applicantName} has submitted an application for {$position}.",
                $link
            );
        }
    } catch (Throwable $e) {
        error_log('notifyHRofNewApplicant failed: ' . $e->getMessage());
    }
}

/**
 * Resolve the user account that truly OWNS an application (by user_id or
 * email). Used to target applicant-facing notifications (e.g. "Application
 * update") to the applicant — never to an HR/manager/admin account.
 *
 * Root cause this guards against: a public application submitted while an
 * HR/manager/admin account was logged in stored that STAFF account id in
 * applicants.user_id. Trusting it would route applicant-facing notifications
 * into the HR/Admin notification bell. So a resolved account is accepted only
 * if its role can own an application (applicant/employee); otherwise we fall
 * back to a role-safe email match, and return null when no genuine applicant
 * account exists (the notification is then suppressed rather than misrouted).
 */
function applicantUserId(array $applicant): ?int
{
    $ownedRoles = ['applicant', 'employee'];

    $candidateIds = [];
    if (!empty($applicant['user_id'])) {
        $candidateIds[] = (int) $applicant['user_id'];
    }
    $email = strtolower(trim((string) ($applicant['email'] ?? '')));

    try {
        // 1) Resolve the stored user_id, but only if it is NOT a staff account
        //    (hr/manager/admin). A staff account can never be the applicant owner.
        $resolved = null;
        if ($candidateIds) {
            $in = implode(',', array_map('intval', $candidateIds));
            $stmt = db()->prepare("SELECT id, role FROM users WHERE id IN ($in) AND is_active = 1 LIMIT 1");
            $stmt->execute();
            $resolved = $stmt->fetch();
        }
        if ($resolved && in_array((string) $resolved['role'], $ownedRoles, true)) {
            return (int) $resolved['id'];
        }

        // 2) Fall back to a role-safe email match (never an HR/manager/admin).
        if ($email !== '') {
            $stmt = db()->prepare("SELECT id, role FROM users WHERE email = ? AND is_active = 1 AND role IN ('applicant','employee') LIMIT 1");
            $stmt->execute([$email]);
            if ($row = $stmt->fetch()) {
                return (int) $row['id'];
            }
        }

        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Fetch the notification data for one user: the active (non-dismissed)
 * unread count plus the newest active items shown in the header bell.
 * Always scoped to the given user id — never to the requester's session,
 * so the caller explicitly supplies the owner (prevents IDOR).
 */
function notification_data(?int $userId): array
{
    $uid = (int) ($userId ?? 0);
    if ($uid <= 0) {
        return ['count' => 0, 'items' => [], 'latest_id' => 0, 'latest_ts' => ''];
    }
    $count = (int) db()->query(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ' . $uid . ' AND is_read = 0 AND dismissed_at IS NULL'
    )->fetchColumn();

    $stmt = db()->prepare(
        'SELECT * FROM notifications WHERE user_id = ? AND dismissed_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 8'
    );
    $stmt->execute([$uid]);
    $items = $stmt->fetchAll();

    $latestId = 0;
    $latestTs = '';
    if (!empty($items)) {
        $latestId = (int) $items[0]['id'];
        $latestTs = (string) ($items[0]['created_at'] ?? '');
    }
    return ['count' => $count, 'items' => $items, 'latest_id' => $latestId, 'latest_ts' => $latestTs];
}

/**
 * Shared renderer for the header notification bell dropdown body. Used by
 * BOTH the server-rendered page and the AJAX polling endpoint so the markup
 * (and hence styling/layout) is a single source of truth. Returns the inner
 * HTML of the #notifDropdown element (head pill, list, view-all, remove-all).
 */
function notification_dropdown_html(?int $userId): string
{
    $data = notification_data($userId);
    $count = (int) $data['count'];
    $items = $data['items'];

    $html = '<div class="notif-head">Notifications' . ($count > 0 ? ' <span class="notif-count-pill">' . $count . ' new</span>' : '') . '</div>';

    if (empty($items)) {
        $html .= '<div class="notif-empty">No notifications yet.</div>';
    } else {
        $html .= '<ul class="notif-list">';
        foreach ($items as $n) {
            $html .= '<li class="notif-item-row ' . ((int) $n['is_read'] ? '' : 'unread') . '">'
                . '<form method="post" action="' . e(BASE_URL) . '/modules/employee/notification_read.php" class="notif-item-form">'
                . csrf_field()
                . '<input type="hidden" name="id" value="' . (int) $n['id'] . '">'
                . '<button type="submit" class="notif-item-btn">'
                . '<span class="notif-title">' . e($n['title']) . '</span>'
                . '<span class="notif-msg">' . e($n['message']) . '</span>'
                . '<span class="notif-time">' . date('M j, Y g:i A', strtotime($n['created_at'])) . '</span>'
                . '</button>'
                . '</form>'
                . '<form method="post" action="' . e(BASE_URL) . '/modules/employee/notification_delete.php" class="notif-item-del">'
                . csrf_field()
                . '<input type="hidden" name="id" value="' . (int) $n['id'] . '">'
                . '<button type="submit" class="notif-del-btn" title="Remove from bell" aria-label="Remove from bell">&times;</button>'
                . '</form>'
                . '</li>';
        }
        $html .= '</ul>';
    }

    if (isEmployee() || isHRorManager()) {
        $html .= '<a class="notif-view-all" href="' . e(BASE_URL) . '/modules/employee/notifications.php">View all notifications</a>';
    }
    if (!empty($items)) {
        $html .= '<form method="post" action="' . e(BASE_URL) . '/modules/employee/notification_remove_all.php" class="notif-remove-all-form">'
            . csrf_field()
            . '<button type="submit" class="notif-remove-all" data-confirm-removeall>Remove All</button>'
            . '</form>';
    }
    return $html;
}

/**
 * Hybrid maintenance engine — see includes/maintenance.php.
 * maintenance_check() is the central gate: it runs every time functions.php
 * loads (which every page in the app requires) and blocks FULL / LIMITED
 * requests before any protected code executes.
 */
require_once __DIR__ . '/maintenance.php';
maintenance_check();

require_once __DIR__ . '/csrf.php';
