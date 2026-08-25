<?php
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/../config/database.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
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

function statusBadge(string $status): string
{
    $colors = [
        'new' => 'badge-new',
        'screening' => 'badge-info',
        'shortlisted' => 'badge-primary',
        'interview' => 'badge-warning',
        'offered' => 'badge-primary',
        'hired' => 'badge-success',
        'rejected' => 'badge-danger',
        'draft' => 'badge-secondary',
        'open' => 'badge-success',
        'closed' => 'badge-danger',
        'filled' => 'badge-primary',
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

/** Resolve the user account linked to an applicant row (by user_id or email). */
function applicantUserId(array $applicant): ?int
{
    if (!empty($applicant['user_id'])) {
        return (int) $applicant['user_id'];
    }
    try {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([strtolower((string) $applicant['email'])]);
        return ($row = $stmt->fetch()) ? (int) $row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

require_once __DIR__ . '/csrf.php';
