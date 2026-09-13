<?php
$pageTitle = 'Dashboard';
$currentModule = 'dashboard';
$bodyClass = 'page-dashboard';
require_once __DIR__ . '/includes/auth.php';

// Distinct body class for the employee self-service dashboard so its compact
// enterprise layout is scoped to this page only (HR dashboard stays unchanged).
// The employee dashboard defaults to the existing collapsed icon rail.
if (isEmployee()) {
    $bodyClass .= ' ess-dashboard';
    $sidebarCollapsedByDefault = true;
}

// Role guards must run BEFORE any output (header.php streams HTML).
// Unauthenticated visitors must not see this page — redirect them to login.
requireLogin();

if (isApplicant()) {
    redirect(BASE_URL . '/modules/applicant/dashboard.php');
}

require_once __DIR__ . '/includes/header.php';

// ---------------------------------------------------------------------------
// EMPLOYEE SELF-SERVICE DASHBOARD — same design system, employee-scoped data.
// ---------------------------------------------------------------------------
if (isEmployee()) {
    $user = getCurrentUser();
    $employeeId = $user['employee_id'] ?? null;
    $uid = (int) $_SESSION['user_id'];

    // ---- Summary counts (employee-scoped, from real HR1 data) ----------------
    $pendingLeave = 0;
    $docCount = 0;
    $trainingCount = 0; // Training/Learning is a future HR3 integration — no HR1 source exists yet.
    if ($employeeId) {
        $s = db()->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'pending'");
        $s->execute([$employeeId]);
        $pendingLeave = (int) $s->fetchColumn();

        $s = db()->prepare('SELECT COUNT(*) FROM employee_documents WHERE employee_id = ?');
        $s->execute([$employeeId]);
        $docCount = (int) $s->fetchColumn();
    }

    // Unread notification count for this account (matches the header bell source).
    $s = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND dismissed_at IS NULL');
    $s->execute([$uid]);
    $unreadNotifs = (int) $s->fetchColumn();

    // ---- My Career — summary from the existing ESS modules -------------------
    $activeGoals = 0;
    $latestReview = null;
    $compCount = 0;
    $devActive = 0;
    if ($employeeId) {
        $s = db()->prepare("SELECT COUNT(*) FROM employee_goals WHERE employee_id = ? AND status IN ('not_started','in_progress')");
        $s->execute([$employeeId]);
        $activeGoals = (int) $s->fetchColumn();

        $s = db()->prepare('SELECT r.status, r.final_rating, p.name AS period_name
                            FROM performance_reviews r
                            LEFT JOIN review_periods p ON p.id = r.period_id
                            WHERE r.employee_id = ?
                            ORDER BY r.id DESC LIMIT 1');
        $s->execute([$employeeId]);
        $latestReview = $s->fetch() ?: null;

        $s = db()->prepare("SELECT COUNT(*) FROM employee_competencies WHERE employee_id = ? AND status = 'active'");
        $s->execute([$employeeId]);
        $compCount = (int) $s->fetchColumn();

        $s = db()->prepare("SELECT COUNT(*) FROM development_plans WHERE employee_id = ? AND status = 'in_progress'");
        $s->execute([$employeeId]);
        $devActive = (int) $s->fetchColumn();
    }

    $perfSummary = 'No reviews yet';
    if ($latestReview) {
        $periodLabel = trim((string) ($latestReview['period_name'] ?? '')) !== '' ? $latestReview['period_name'] : 'Performance review';
        if ($latestReview['final_rating'] !== null) {
            $perfSummary = $periodLabel . ' · ' . (int) $latestReview['final_rating'] . '/5';
        } else {
            $perfSummary = $periodLabel . ' · ' . str_replace('_', ' ', $latestReview['status']);
        }
    }

    // ---- Attendance — this week's summary (only if HR1 provides an attendance table) ---
    $attWork = null;
    $attLate = null;
    $attOver = null;
    $fmtAttDur = static function (?int $sec): string {
        if ($sec === null) { return '—'; }
        $hours = intdiv($sec, 3600);
        $mins  = str_pad((string) intdiv($sec % 3600, 60), 2, '0', STR_PAD_LEFT);
        return $hours . ':' . $mins;
    };
    if ($employeeId) {
        $at = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $at->execute(['attendance']);
        if ((int) $at->fetchColumn() > 0) {
            try {
                $s = db()->prepare('SELECT work_hours, late_minutes, overtime FROM attendance
                                    WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?');
                $s->execute([$employeeId, date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))]);
                $attRows = $s->fetchAll();
                if ($attRows) {
                    $attToSec = static function ($v): ?int {
                        if ($v === null || $v === '') { return null; }
                        if (preg_match('/^(\d{1,3}):([0-5]\d)(?::([0-5]\d))?$/', (string) $v, $m)) {
                            return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) ($m[3] ?? 0);
                        }
                        return (int) round(((float) $v) * 3600);
                    };
                    $tw = $tl = $to = $cw = $cl = $co = 0;
                    foreach ($attRows as $ar) {
                        $w = $attToSec($ar['work_hours'] ?? null); if ($w !== null) { $tw += $w; $cw++; }
                        $l = $ar['late_minutes'] ?? null;           if ($l !== null && $l !== '') { $tl += (int) $l; $cl++; }
                        $o = $attToSec($ar['overtime'] ?? null);    if ($o !== null) { $to += $o; $co++; }
                    }
                    if ($cw) { $attWork = (int) round($tw / $cw); }
                    if ($cl) { $attLate = (int) round($tl / $cl); }
                    if ($co) { $attOver = (int) round($to / $co); }
                }
            } catch (Throwable $e) {
                /* attendance table columns differ — fall back to the empty state */
            }
        }
    }

    // ---- Recent Activity — merged real events from existing HR1 sources ------
    $activity = [];
    if ($employeeId) {
        $s = db()->prepare('SELECT title, updated_at FROM employee_goals WHERE employee_id = ? ORDER BY updated_at DESC LIMIT 5');
        $s->execute([$employeeId]);
        foreach ($s->fetchAll() as $g) {
            $activity[] = ['title' => 'Goal updated', 'desc' => $g['title'], 'ts' => $g['updated_at'], 'icon' => 'goal'];
        }
        $s = db()->prepare('SELECT document_name, uploaded_at FROM employee_documents WHERE employee_id = ? ORDER BY uploaded_at DESC LIMIT 5');
        $s->execute([$employeeId]);
        foreach ($s->fetchAll() as $d) {
            $activity[] = ['title' => 'Document added', 'desc' => $d['document_name'], 'ts' => $d['uploaded_at'], 'icon' => 'doc'];
        }
        $s = db()->prepare("SELECT leave_type, created_at FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 5");
        $s->execute([$employeeId]);
        foreach ($s->fetchAll() as $l) {
            $activity[] = ['title' => 'Leave request submitted', 'desc' => ucfirst($l['leave_type']) . ' leave', 'ts' => $l['created_at'], 'icon' => 'leave'];
        }
    }
    $s = db()->prepare("SELECT event_type, details, created_at FROM security_log WHERE user_id = ? AND event_type IN ('profile_updated','password_changed') ORDER BY id DESC LIMIT 5");
    $s->execute([$uid]);
    foreach ($s->fetchAll() as $e) {
        $activity[] = [
            'title' => $e['event_type'] === 'profile_updated' ? 'Profile updated' : 'Password changed',
            'desc'  => (string) ($e['details'] ?? ''),
            'ts'    => $e['created_at'],
            'icon'  => $e['event_type'] === 'profile_updated' ? 'profile' : 'password',
        ];
    }
    usort($activity, static function ($a, $b) { return strcmp($b['ts'], $a['ts']); });
    $activity = array_slice($activity, 0, 5);

    // ---- Upcoming — real dates only (leaves, goal due dates, dev targets) ----
    $upcoming = [];
    if ($employeeId) {
        $s = db()->prepare("SELECT leave_type, start_date FROM leave_requests WHERE employee_id = ? AND start_date >= CURDATE() AND status IN ('pending','approved') ORDER BY start_date ASC LIMIT 5");
        $s->execute([$employeeId]);
        foreach ($s->fetchAll() as $l) {
            $upcoming[] = ['title' => ucfirst($l['leave_type']) . ' leave', 'desc' => 'Leave request', 'date' => $l['start_date'], 'icon' => 'leave', 'url' => BASE_URL . '/modules/employee/leave.php'];
        }
        $s = db()->prepare("SELECT title, due_date FROM employee_goals WHERE employee_id = ? AND due_date >= CURDATE() AND status IN ('not_started','in_progress') ORDER BY due_date ASC LIMIT 5");
        $s->execute([$employeeId]);
        foreach ($s->fetchAll() as $g) {
            $upcoming[] = ['title' => 'Goal due', 'desc' => $g['title'], 'date' => $g['due_date'], 'icon' => 'goal', 'url' => BASE_URL . '/modules/employee/goals.php'];
        }
        $s = db()->prepare("SELECT title, target_date FROM development_plans WHERE employee_id = ? AND target_date >= CURDATE() AND status IN ('draft','in_progress') ORDER BY target_date ASC LIMIT 5");
        $s->execute([$employeeId]);
        foreach ($s->fetchAll() as $d) {
            $upcoming[] = ['title' => 'Development target', 'desc' => $d['title'], 'date' => $d['target_date'], 'icon' => 'dev', 'url' => BASE_URL . '/modules/employee/development.php'];
        }
    }
    usort($upcoming, static function ($a, $b) { return strcmp($a['date'], $b['date']); });
    $upcoming = array_slice($upcoming, 0, 4);

    // Compact activity timestamp: "Today, 10:24 AM" today, otherwise "Sep 12".
    $fmtActivityTime = static function (string $ts): string {
        $t = strtotime($ts);
        if (!$t) {
            return '';
        }
        if (date('Y-m-d', $t) === date('Y-m-d')) {
            return 'Today, ' . date('g:i A', $t);
        }
        return date('M j', $t);
    };

    // Monochrome outline icons shared across the dashboard sections.
    $dashIcon = static function (string $name): string {
        $icons = [
            'leave'   => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M8 11h8"/><path d="M8 15h5"/>',
            'doc'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/>',
            'bell'    => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'training'=> '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            'goal'    => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
            'performance' => '<path d="M22 12h-4l-3 9-6.5-18L5 12H2"/>',
            'competency'  => '<path d="M12 2 2 7l10 5 10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>',
            'dev'     => '<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
            'learning'=> '<path d="M22 10v6"/><path d="M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
            'rocket'  => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
            'award'   => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
            'profile' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'password'=> '<path d="M21 2l-6.5 6.5"/><circle cx="7.5" cy="15.5" r="5.5"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
            'cal'     => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'chevron' => '<path d="M9 18l6-6-6-6"/>',
        ];
        $body = $icons[$name] ?? '';
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
    };
?>

<div class="welcome-banner fade-in-up">
    <div class="welcome-content">
        <h1 class="welcome-title"><?= e($greeting) ?>, <?= e(($user['first_name'] ?? '') ?: $user['username']) ?>!</h1>
        <p class="welcome-subtitle"><?= e($user['job_title'] ?? 'Employee') ?><?= !empty($user['department_name']) ? ' · ' . e($user['department_name']) : '' ?></p>
    </div>
    <div class="welcome-status">
        <span class="status-label">System Status</span>
        <span class="status-value"><span class="live-dot"></span> Synced</span>
    </div>
</div>

<section class="dash-summary-grid" aria-label="Workspace summary">
    <a class="dash-sum-card fade-in-up" style="animation-delay:.05s" href="<?= BASE_URL ?>/modules/employee/leave.php">
        <span class="dash-sum-head">
            <span class="dash-sum-icon"><?= $dashIcon('leave') ?></span>
            <span class="dash-sum-arrow"><?= $dashIcon('chevron') ?></span>
        </span>
        <span class="dash-sum-title">Leave Requests</span>
        <span class="dash-sum-value"><?= $pendingLeave ?></span>
        <span class="dash-sum-sub">Pending Requests</span>
    </a>
    <a class="dash-sum-card fade-in-up" style="animation-delay:.1s" href="<?= BASE_URL ?>/modules/employee/documents.php">
        <span class="dash-sum-head">
            <span class="dash-sum-icon"><?= $dashIcon('doc') ?></span>
            <span class="dash-sum-arrow"><?= $dashIcon('chevron') ?></span>
        </span>
        <span class="dash-sum-title">Documents</span>
        <span class="dash-sum-value"><?= $docCount ?></span>
        <span class="dash-sum-sub">Total Documents</span>
    </a>
    <a class="dash-sum-card fade-in-up" style="animation-delay:.15s" href="<?= BASE_URL ?>/modules/employee/notifications.php">
        <span class="dash-sum-head">
            <span class="dash-sum-icon"><?= $dashIcon('bell') ?></span>
            <span class="dash-sum-arrow"><?= $dashIcon('chevron') ?></span>
        </span>
        <span class="dash-sum-title">Notifications</span>
        <span class="dash-sum-value"><?= $unreadNotifs ?></span>
        <span class="dash-sum-sub">Unread Notifications</span>
    </a>
    <a class="dash-sum-card fade-in-up" style="animation-delay:.2s" href="<?= BASE_URL ?>/modules/employee/trainings.php">
        <span class="dash-sum-head">
            <span class="dash-sum-icon"><?= $dashIcon('training') ?></span>
            <span class="dash-sum-arrow"><?= $dashIcon('chevron') ?></span>
        </span>
        <span class="dash-sum-title">Training</span>
        <span class="dash-sum-value"><?= $trainingCount ?></span>
        <span class="dash-sum-sub">Assigned Training</span>
    </a>
</section>

<div class="dash-workspace">
    <div class="dash-main-col">
        <!-- My Career -->
        <div class="dash-panel fade-in-up" style="animation-delay:.25s">
            <div class="dash-panel-head">
                <h2>My Career</h2>
            </div>
            <a class="dash-row" href="<?= BASE_URL ?>/modules/employee/goals.php">
                <span class="dash-row-icon"><?= $dashIcon('goal') ?></span>
                <span class="dash-row-label">My Goals</span>
                <span class="dash-row-chevron"><?= $dashIcon('chevron') ?></span>
            </a>
            <a class="dash-row" href="<?= BASE_URL ?>/modules/employee/performance.php">
                <span class="dash-row-icon"><?= $dashIcon('performance') ?></span>
                <span class="dash-row-label">My Performance</span>
                <span class="dash-row-chevron"><?= $dashIcon('chevron') ?></span>
            </a>
            <a class="dash-row" href="<?= BASE_URL ?>/modules/employee/competencies.php">
                <span class="dash-row-icon"><?= $dashIcon('competency') ?></span>
                <span class="dash-row-label">My Competencies</span>
                <span class="dash-row-chevron"><?= $dashIcon('chevron') ?></span>
            </a>
            <a class="dash-row" href="<?= BASE_URL ?>/modules/employee/trainings.php">
                <span class="dash-row-icon"><?= $dashIcon('training') ?></span>
                <span class="dash-row-label">My Trainings</span>
                <span class="dash-row-chevron"><?= $dashIcon('chevron') ?></span>
            </a>
            <a class="dash-row" href="<?= BASE_URL ?>/modules/employee/learning.php">
                <span class="dash-row-icon"><?= $dashIcon('learning') ?></span>
                <span class="dash-row-label">My Learning</span>
                <span class="dash-row-chevron"><?= $dashIcon('chevron') ?></span>
            </a>
            <a class="dash-row" href="<?= BASE_URL ?>/modules/employee/development.php">
                <span class="dash-row-icon"><?= $dashIcon('rocket') ?></span>
                <span class="dash-row-label">My Development</span>
                <span class="dash-row-chevron"><?= $dashIcon('chevron') ?></span>
            </a>
            <a class="dash-row" href="<?= BASE_URL ?>/modules/employee/recognition.php">
                <span class="dash-row-icon"><?= $dashIcon('award') ?></span>
                <span class="dash-row-label">My Recognition</span>
                <span class="dash-row-chevron"><?= $dashIcon('chevron') ?></span>
            </a>
        </div>

        <!-- Recent Activity -->
        <div class="dash-panel fade-in-up" style="animation-delay:.3s">
            <div class="dash-panel-head">
                <h2>Recent Activity</h2>
                <a class="dash-view-all" href="<?= BASE_URL ?>/modules/employee/notifications.php">View all</a>
            </div>
            <?php if (empty($activity)): ?>
            <div class="dash-empty">No recent activity yet.</div>
            <?php else: ?>
                <?php foreach ($activity as $act): ?>
                <div class="dash-row">
                    <span class="dash-row-icon"><?= $dashIcon($act['icon']) ?></span>
                    <span class="dash-row-body">
                        <span class="dash-row-label"><?= e($act['title']) ?></span>
                        <span class="dash-row-desc"><?= e($act['desc']) ?></span>
                    </span>
                    <span class="dash-row-meta"><?= e($fmtActivityTime($act['ts'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Attendance -->
        <div class="dash-panel fade-in-up" style="animation-delay:.35s">
            <div class="dash-panel-head">
                <h2>Attendance</h2>
                <a class="dash-view-all" href="<?= BASE_URL ?>/modules/employee/attendance.php">View all</a>
            </div>
            <div class="dash-att-stats">
                <div class="dash-att-stat"><span class="dash-att-value<?= $attWork === null ? ' muted' : '' ?>"><?= $attWork === null ? '—' : $fmtAttDur($attWork) ?></span><span class="dash-att-label">Work Hours</span></div>
                <div class="dash-att-stat"><span class="dash-att-value<?= $attLate === null ? ' muted' : '' ?>"><?= $attLate === null ? '—' : $attLate . 'm' ?></span><span class="dash-att-label">Late</span></div>
                <div class="dash-att-stat"><span class="dash-att-value<?= $attOver === null ? ' muted' : '' ?>"><?= $attOver === null ? '—' : $fmtAttDur($attOver) ?></span><span class="dash-att-label">Overtime</span></div>
            </div>
            <div class="dash-att-meta"><span class="live-dot"></span> This Week<span class="dash-att-note"><?= $attWork === null ? '· no records' : '· real-time data' ?></span></div>
        </div>
    </div>

    <aside class="dash-side-col">
        <!-- Upcoming -->
        <div class="dash-panel fade-in-up" style="animation-delay:.35s">
            <div class="dash-panel-head">
                <h2>Upcoming</h2>
                <a class="dash-view-all" href="<?= BASE_URL ?>/modules/employee/trainings.php">View all</a>
            </div>
            <?php if (empty($upcoming)): ?>
            <div class="dash-empty">Nothing upcoming.</div>
            <?php else: ?>
                <?php foreach ($upcoming as $u): ?>
                <a class="dash-row" href="<?= e($u['url']) ?>">
                    <span class="dash-date"><strong><?= e(strtoupper(date('M', strtotime($u['date'])))) ?></strong><span><?= e(date('j', strtotime($u['date']))) ?></span></span>
                    <span class="dash-row-body">
                        <span class="dash-row-label"><?= e($u['title']) ?></span>
                        <span class="dash-row-desc"><?= e($u['desc']) ?></span>
                    </span>
                    <span class="dash-row-chevron"><?= $dashIcon('chevron') ?></span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>

<style>
/* Employee dashboard — compact enterprise layout (scoped to body.ess-dashboard).
   Reuses the existing design tokens (surface, border, radius, shadows). */
.ess-dashboard .dashboard-logo-bg { display: none; }
.ess-dashboard .live-badge { display: none; }
.ess-dashboard .container { padding: 1.25rem 1.5rem 2rem; }

/* Compact purple greeting banner — scoped to the employee ESS dashboard only.
   Reuses the shared .welcome-banner purple design; only spacing trimmed. */
.ess-dashboard .welcome-banner {
    margin-bottom: 1.25rem;
}

/* Compact top summary cards with very subtle per-card accents. */
.dash-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}
.dash-sum-card {
    display: flex;
    flex-direction: column;
    gap: .2rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .8rem 1rem;
    box-shadow: var(--shadow-xs);
    text-decoration: none;
    color: inherit;
    transition: border-color .2s ease, box-shadow .2s ease;
}
.dash-sum-card:hover { border-color: var(--purple-light); box-shadow: var(--shadow-md); text-decoration: none; }
.dash-sum-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .35rem; }
.dash-sum-icon { display: inline-flex; color: var(--muted); }
.dash-sum-arrow { display: inline-flex; color: var(--muted); opacity: .45; }
.dash-sum-card:hover .dash-sum-arrow { opacity: .85; }
.dash-sum-title { font-size: .68rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; }
.dash-sum-value { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); letter-spacing: -.02em; line-height: 1.15; font-variant-numeric: tabular-nums; }
.dash-sum-sub { font-size: .7rem; color: var(--muted); }

/* Two-column workspace: wide main column + narrow side column. */
.dash-workspace {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
    gap: .875rem;
    align-items: start;
}
.dash-main-col { display: flex; flex-direction: column; gap: .875rem; min-width: 0; }
.dash-side-col { display: flex; flex-direction: column; gap: .875rem; min-width: 0; }

.dash-panel {
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem 1.05rem .95rem;
    box-shadow: var(--shadow-xs);
    min-width: 0;
}
.dash-panel-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .4rem; padding-bottom: .6rem; border-bottom: 1px solid var(--border); }
.dash-panel-head h2 { font-size: .9375rem; font-weight: 700; color: var(--text-dark); letter-spacing: -.01em; margin: 0; }
.dash-view-all { font-size: .75rem; font-weight: 600; color: var(--muted); text-decoration: none; }
.dash-view-all:hover { color: var(--purple); text-decoration: none; }

.dash-row {
    display: flex;
    align-items: center;
    gap: .7rem;
    width: 100%;
    min-height: 44px;
    padding: .55rem 0;
    border: 0;
    border-top: 1px solid var(--border);
    background: none;
    cursor: pointer;
    text-align: left;
    text-decoration: none;
    color: inherit;
}
.dash-row:first-child { border-top: 0; }
.dash-row:hover { text-decoration: none; }
.dash-row-icon { display: inline-flex; flex: 0 0 auto; color: var(--purple); opacity: .5; }
.dash-row-label { flex: 1 1 auto; min-width: 0; font-size: .83rem; font-weight: 600; color: var(--text-dark); }
.dash-row-body { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: .05rem; }
.dash-row-desc { margin: 0; font-size: .7rem; color: var(--muted); line-height: 1.35; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dash-row-meta { flex: 0 0 auto; font-size: .68rem; color: var(--muted); font-weight: 500; white-space: nowrap; }
.dash-row-chevron { flex: 0 0 auto; display: inline-flex; color: var(--text-dark); opacity: .35; transition: opacity .15s ease; }
.dash-row:hover .dash-row-chevron { opacity: .85; }
.dash-row:hover .dash-row-icon { opacity: .9; }

.dash-date {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 46px;
    padding: .3rem 0 .26rem;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface);
    box-shadow: var(--shadow-xs);
}
.dash-date strong { font-size: .62rem; font-weight: 700; color: var(--muted); letter-spacing: .05em; }
.dash-date span { font-size: 1.05rem; font-weight: 700; color: var(--text-dark); line-height: 1.1; font-variant-numeric: tabular-nums; }

.dash-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem .5rem;
    text-align: center;
    color: var(--muted);
    font-size: .8rem;
    line-height: 1.5;
}

.dash-att-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .75rem;
}
.dash-att-stat {
    display: flex;
    flex-direction: column;
    gap: .15rem;
    padding: .6rem .75rem;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
}
.dash-att-value {
    font-size: .98rem;
    font-weight: 700;
    color: var(--text-dark);
    letter-spacing: -.01em;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
}
.dash-att-value.muted { color: var(--muted); font-weight: 600; }
.dash-att-label {
    font-size: .66rem;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.dash-att-meta {
    display: flex;
    align-items: center;
    gap: .35rem;
    margin-top: .6rem;
    font-size: .7rem;
    font-weight: 600;
    color: var(--text-dark);
}
.dash-att-note { color: var(--muted); font-weight: 500; }

@media (max-width: 1100px) {
    .dash-workspace { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
    .dash-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 560px) {
    .ess-dashboard .container { padding: 0 1rem 1.5rem; }
    .ess-dashboard .welcome-status { text-align: left; }
    .dash-summary-grid { grid-template-columns: 1fr; }
}
</style>

<?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// HR / ADMIN DASHBOARD — management overview in the same visual format as the
// Employee dashboard (hero banner + KPI cards), populated with HR data only.
// ---------------------------------------------------------------------------
if (isHRorManager()) {
    $user = getCurrentUser();

    $openJobs   = (int) db()->query("SELECT COUNT(*) FROM job_postings WHERE status = 'open'")->fetchColumn();
    $applicants = (int) db()->query('SELECT COUNT(*) FROM applicants')->fetchColumn();
    $onboarding = (int) db()->query("SELECT COUNT(*) FROM employee_onboarding WHERE status <> 'completed'")->fetchColumn();
    $pendingReq = (int) db()->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
    $hrRecords  = (int) db()->query('SELECT COUNT(*) FROM employee_documents')->fetchColumn();
?>

<section class="welcome-banner fade-in-up">
    <div class="welcome-content">
        <p class="welcome-greeting"><?= e($greeting) ?>, <?= e(($user['first_name'] ?? '') ?: $user['username']) ?>!</p>
        <h1 class="welcome-title">HR &amp; Administration Workspace</h1>
        <p class="welcome-subtitle"></p>
    </div>
    <div class="welcome-status">
        <span class="status-label">System Status</span>
        <span class="status-value"><span class="live-dot"></span> Synced</span>
    </div>
</section>

<div class="stats-grid stats-grid-3">
    <div class="kpi-card fade-in-up" style="animation-delay:.1s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-purple"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></span>
        </div>
        <span class="kpi-value"><?= $openJobs ?></span>
        <span class="kpi-label">Active Recruitment</span>
        <a href="<?= BASE_URL ?>/modules/recruitment/index.php" class="kpi-link">View Recruitment &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.15s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-blue"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
        </div>
        <span class="kpi-value"><?= $applicants ?></span>
        <span class="kpi-label">Applicants</span>
        <a href="<?= BASE_URL ?>/modules/applicants/index.php" class="kpi-link">View Applicants &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.25s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-orange"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
        </div>
        <span class="kpi-value"><?= $onboarding ?></span>
        <span class="kpi-label">Onboarding</span>
        <a href="<?= BASE_URL ?>/modules/onboarding/index.php" class="kpi-link">View Onboarding &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.3s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-purple"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
        </div>
        <span class="kpi-value"><?= $pendingReq ?></span>
        <span class="kpi-label">Pending Requests</span>
        <a href="<?= BASE_URL ?>/modules/ess/index.php" class="kpi-link">View Requests &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.35s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-blue"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span>
        </div>
        <span class="kpi-value"><?= $hrRecords ?></span>
        <span class="kpi-label">HR Records</span>
        <a href="<?= BASE_URL ?>/modules/records/index.php" class="kpi-link">View Records &rarr;</a>
    </div>
</div>

<!-- ===================== Applicant Statistics — Graph 1 ===================== -->
<section class="panel applicant-stats fade-in-up" style="animation-delay:.4s" aria-label="Applicant Statistics">
    <div class="panel-header">
        <div>
            <h2>Applicant Statistics</h2>
            <p class="panel-desc">Track application activity and recruitment trends over time.</p>
        </div>
    </div>

    <form class="inline-form compact applicant-stats-filters" id="statsFilters">
        <div class="form-group stats-field">
            <label for="statsPeriod">Period</label>
            <select id="statsPeriod" name="period">
                <option value="daily">Daily</option>
                <option value="monthly" selected>Monthly</option>
                <option value="yearly">Yearly</option>
            </select>
        </div>
        <div class="form-group stats-field" id="statsYearWrap">
            <label for="statsYear">Year</label>
            <select id="statsYear" name="year"></select>
        </div>
        <div class="form-group stats-field stats-field-job">
            <label for="statsPosition">Job Position</label>
            <select id="statsPosition" name="position"></select>
        </div>
    </form>

    <div class="stats-insights" id="statsInsights">
        <div class="stats-insight-card">
            <span class="stats-insight-label">Peak Application Month</span>
            <span class="stats-insight-value" id="insightPeakMonth">No data</span>
            <span class="stats-insight-sub" id="insightPeakMonthSub"></span>
        </div>
        <div class="stats-insight-card">
            <span class="stats-insight-label">Most Applied Job</span>
            <span class="stats-insight-value" id="insightTopJob">No data</span>
            <span class="stats-insight-sub" id="insightTopJobSub"></span>
        </div>
    </div>

    <div class="stats-chart-wrap" id="statsChartWrap"></div>

    <div class="stats-state" id="statsState" hidden></div>
</section>

<!-- ===================== Recruitment Overview — current month ===================== -->
<section class="panel applicant-stats fade-in-up" style="animation-delay:.45s" aria-label="Recruitment Overview">
    <div class="panel-header recruit-head">
        <div>
            <h2>Recruitment Overview</h2>
            <p class="panel-desc" id="recruitSubtitle">Current month recruitment funnel.</p>
        </div>
        <div class="recruit-month" id="recruitMonthWrap">
            <button type="button" class="recruit-month-trigger" id="recruitMonthBtn"
                    aria-haspopup="listbox" aria-expanded="false">
                <span id="recruitMonthLabel">…</span>
                <span class="recruit-month-arrow" aria-hidden="true"></span>
            </button>
            <ul class="recruit-month-menu" id="recruitMonthMenu" role="listbox"
                aria-label="Select recruitment month" hidden></ul>
        </div>
    </div>

    <div class="recruit-funnel" id="recruitFunnel"></div>
</section>

<style>
/* Applicant Statistics — uses the panel/card and form styles already defined
   in style.css. Only structural/spacing additions live here so the section
   reads as a natural part of the existing dashboard. */
.applicant-stats { margin-bottom: 1.5rem; }
.applicant-stats .panel-header { margin-bottom: 1rem; }
.applicant-stats-filters { margin-bottom: 1.25rem; gap: .9rem; }
.stats-field { min-width: 120px; }
.stats-field select { min-width: 120px; }
.stats-field-job select { min-width: 200px; }
.stats-chart-wrap {
    position: relative; width: 100%;
    background: var(--sidebar-bg);
    border: 1px solid rgba(255, 255, 255, .05);
    border-radius: var(--radius-sm);
    padding: 1.15rem 1rem .7rem;
    box-shadow: 0 16px 34px -20px rgba(21, 21, 33, .55);
}
.stats-chart-wrap svg { display: block; width: 100%; height: auto; overflow: visible; }
.stats-chart-wrap .stats-bar { transition: fill .15s ease, fill-opacity .15s ease; }
.stats-state { padding: 2rem 1rem; text-align: center; color: var(--muted); font-size: .9rem; background: var(--bg); border: 1px dashed var(--border); border-radius: var(--radius-sm); }
.stats-loading { padding: .4rem 0 .2rem; text-align: center; color: var(--sidebar-text); font-size: .85rem; }
.stats-tooltip {
    position: absolute; z-index: 10; pointer-events: none; transform: translate(-50%, -130%);
    background: var(--text-dark); color: #fff; border-radius: 6px; padding: .35rem .6rem;
    font-size: .72rem; line-height: 1.35; white-space: nowrap; box-shadow: 0 8px 20px -8px rgba(27,37,89,.5);
}
.stats-tooltip strong { display: block; font-weight: 600; }
.stats-tooltip[hidden] { display: none; }

/* ---- Applicant statistics compact insight cards ---- */
.stats-insights {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: .9rem;
    margin-bottom: 1.25rem;
}
.stats-insight-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: .85rem 1rem;
    box-shadow: var(--shadow-xs);
    display: flex;
    flex-direction: column;
    gap: .35rem;
    min-width: 0;
}
.stats-insight-label {
    font-size: .72rem; text-transform: uppercase; letter-spacing: .06em;
    color: var(--muted); font-weight: 700;
}
.stats-insight-value {
    font-size: 1.05rem; font-weight: 700; color: var(--text-dark);
    line-height: 1.25; word-wrap: break-word; overflow-wrap: break-word;
    font-variant-numeric: tabular-nums;
}
.stats-insight-sub {
    font-size: .82rem; color: var(--muted);
}
@media (max-width: 640px) {
    .stats-insights { grid-template-columns: 1fr; }
}

/* ---- Recruitment Overview — compact current-month funnel ---- */
.recruit-head .recruit-month {
    position: relative;
    display: inline-flex;
    align-items: center;
    padding-top: .1rem;
}
.recruit-month-trigger {
    appearance: none;
    border: none;
    background: none;
    display: inline-flex; align-items: center; gap: .4rem;
    padding: 0;
    cursor: pointer;
    font-family: inherit;
    font-size: .95rem; font-weight: 700; letter-spacing: .04em;
    color: var(--text-dark);
    font-variant-numeric: tabular-nums;
}
.recruit-month-trigger:focus-visible {
    outline: 1px solid var(--purple-light);
    outline-offset: 2px; border-radius: 4px;
}
.recruit-month-arrow {
    display: block;
    width: 7px; height: 7px;
    border-right: 1.5px solid var(--text-dark);
    border-bottom: 1.5px solid var(--text-dark);
    transform: rotate(45deg) translateY(-2px);
    opacity: .7;
}
.recruit-month-menu {
    position: absolute; top: calc(100% + 6px); right: 0; z-index: 50;
    min-width: 180px;
    max-height: 165px;               /* ≈ 5 visible months */
    overflow-y: auto;
    margin: 0; padding: .3rem;
    list-style: none;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: var(--shadow-lg);
    scrollbar-width: thin;
    scrollbar-color: rgba(0,0,0,.22) transparent;
}
.recruit-month-menu[hidden] { display: none; }
.recruit-month-menu::-webkit-scrollbar { width: 6px; }
.recruit-month-menu::-webkit-scrollbar-thumb { background: rgba(0,0,0,.18); border-radius: 3px; }
.recruit-month-menu::-webkit-scrollbar-track { background: transparent; }
.recruit-month-menu li {
    padding: .42rem .6rem;
    font-size: .85rem; color: var(--text);
    border-radius: 6px; cursor: pointer;
    white-space: nowrap;
}
.recruit-month-menu li:hover { background: var(--surface-hover); }
.recruit-month-menu li.is-selected {
    font-weight: 700; color: var(--text-dark);
    background: var(--surface-hover);
}
.recruit-month-menu li.is-selected::after {
    content: '✓'; float: right; font-weight: 700;
    color: var(--muted); margin-left: .5rem;
}
.recruit-funnel {
    background: var(--sidebar-bg);
    border: 1px solid rgba(255,255,255,.05);
    border-radius: var(--radius-sm);
    padding: .65rem 1rem .85rem;
    box-shadow: 0 10px 24px -18px rgba(21, 21, 33, .55);
}
.recruit-funnel-row {
    display: grid;
    grid-template-columns: 74px minmax(0, 1fr) auto;
    align-items: center;
    gap: .75rem;
    padding: .3rem 0;
}
.recruit-funnel-label {
    font-size: .8rem; font-weight: 600; color: #C4CDE8;
    white-space: nowrap;
}
.recruit-funnel-bars {
    overflow: hidden;                       /* clip when the max-width cap kicks in */
}
.recruit-funnel-fill {
    display: inline-block;
    height: 10px; border-radius: 5px;
    min-width: 2px;
    transition: width .4s ease;
}
.recruit-funnel-fill.zero { opacity: .22; }
.recruit-funnel-count {
    font-size: .85rem; font-weight: 800; color: #ffffff;
    font-variant-numeric: tabular-nums; min-width: 2ch; text-align: right;
}
.recruit-funnel-empty,
.recruit-funnel-loading {
    padding: 1rem 1rem; text-align: center;
    color: var(--sidebar-text); font-size: .85rem;
}
@media (max-width: 520px) {
    .recruit-funnel { padding: .55rem .75rem .7rem; }
    .recruit-funnel-row { grid-template-columns: 60px minmax(0, 1fr) auto; gap: .55rem; }
}

</style>

<script>
(function () {
    var BASE = '<?= e(BASE_URL) ?>';
    var wrap  = document.getElementById('statsChartWrap');
    var state = document.getElementById('statsState');
    var filters = document.getElementById('statsFilters');
    if (!wrap || !filters) return;

    var periodSel  = document.getElementById('statsPeriod');
    var yearSel    = document.getElementById('statsYear');
    var posSel     = document.getElementById('statsPosition');
    var yearWrap   = document.getElementById('statsYearWrap');
    var SERIES_NAMES   = ['Applied', 'Screen', 'Passed', 'Hired'];
    var SERIES_COLORS  = ['#9D4EDD', '#00B5D8', '#05CD99', '#FFB547'];
    var CHART_COLOR    = '#9D4EDD';  // light purple, original accent — single clear bar colour
    var BAR_OPACITY    = 0.95;
    var GRID_COLOR = 'rgba(255,255,255,0.08)';
    var TEXT_COLOR = '#A3AED0';
    var AXIS_TXT   = '#C4CDE8';

    // Populate the job-position dropdown dynamically from the data response.
    // Options carry the stable job_posting id as the value and the CURRENT
    // title from job_postings as the label, so a rename (e.g. Kargador →
    // Warehouse Associate) shows the new name while keeping the same id.
    // Returns true when the previously selected job no longer exists, in which
    // case the filter is reset to "All Positions".
    function fillPositions(posList) {
        var current = posSel.value;
        var opts = '<option value="">All Positions</option>';
        (posList || []).forEach(function (p) {
            opts += '<option value="' + Number(p.id) + '">' + esc(p.title) + '</option>';
        });
        posSel.innerHTML = opts;
        if (current === '') {
            posSel.value = '';
            return false;
        }
        var stillThere = (posList || []).some(function (p) { return Number(p.id) === Number(current); });
        if (!stillThere) {
            posSel.value = '';
            return true;
        }
        posSel.value = current;
        return false;
    }

    // Populate the year dropdown from the data range.
    function fillYears(minDate, maxDate) {
        var yMin = minDate ? parseInt(minDate.slice(0, 4), 10) : new Date().getFullYear();
        var yMax = maxDate ? parseInt(maxDate.slice(0, 4), 10) : yMin;
        var opts = '';
        for (var y = yMax; y >= yMin; y--) {
            opts += '<option value="' + y + '">' + y + '</option>';
        }
        yearSel.innerHTML = opts;
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function fmtNum(n) {
        return Number(n).toLocaleString('en-US');
    }

    function emptyHandler() {
        wrap.innerHTML = '';
        state.hidden = false;
        state.textContent = 'No application data available for the selected period.';
    }

    function errorHandler() {
        wrap.innerHTML = '';
        state.hidden = false;
        state.textContent = 'Unable to load applicant statistics. Please try again later.';
    }

    // Map the endpoint payload to renderable funnel series. Long daily periods
    // render a single "Applied" series so the chart stays legible.
    function normalizeSeries(data) {
        var collapsed = data.period === 'daily' && (data.labels || []).length > 31;
        if (!collapsed && data.series && data.series.length) {
            return data.series.map(function (s, idx) {
                return {
                    name: s.name || SERIES_NAMES[idx] || 'Applied',
                    values: s.values || [],
                    color: SERIES_COLORS[idx % SERIES_COLORS.length]
                };
            });
        }
        return [{ name: 'Applied', values: data.values || [], color: SERIES_COLORS[0] }];
    }

    // ---- Recruitment Overview — compact current-month funnel -------------
    var funnelWrap   = document.getElementById('recruitFunnel');
    var monthTrigger = document.getElementById('recruitMonthBtn');
    var monthLabelEl = document.getElementById('recruitMonthLabel');
    var monthMenu    = document.getElementById('recruitMonthMenu');
    var subtitleEl   = document.getElementById('recruitSubtitle');
    var funnelMonth  = '';               // currently selected "YYYY-MM"
    var BAR_MAX_PX   = 280;   // fixed cap so bars stay compact, not full-width
    // Subtle funnel bar colours, matching dashboard brand accents.
    var FUNNEL_COLORS = ['#9D4EDD', '#00B5D8', '#05CD99', '#FFB547'];
    var MONTH_FULL  = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var MONTH_SHORT = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];

    // Convert "Y-m" (e.g. "2026-09") to "SEP 2026" / "September 2026".
    function formatMonthShort(ym) {
        var p = String(ym || '').split('-');
        var m = parseInt(p[1], 10);
        if (isNaN(m)) return '';
        return MONTH_SHORT[(m - 1) % 12] + ' ' + p[0];
    }
    function formatFullMonth(ym) {
        var p = String(ym || '').split('-');
        var m = parseInt(p[1], 10);
        if (isNaN(m)) return '';
        return MONTH_FULL[(m - 1) % 12] + ' ' + p[0];
    }

    // Build the 12-month menu (Jan–Dec of the selected year) and mark the
    // currently selected month. Every month of the year is always available.
    function buildMonthMenu(selectedYm) {
        if (!monthMenu) return;
        var y = selectedYm ? parseInt(selectedYm.slice(0, 4), 10) : new Date().getFullYear();
        var html = '';
        for (var m = 1; m <= 12; m++) {
            var ym = y + '-' + (m < 10 ? '0' + m : m);
            html += '<li role="option" aria-selected="' + (ym === selectedYm ? 'true' : 'false') + '"' +
                    ' data-ym="' + ym + '"' + (ym === selectedYm ? ' class="is-selected"' : '') + '>' +
                    formatFullMonth(ym) + '</li>';
        }
        monthMenu.innerHTML = html;
    }

    function openMonthMenu() {
        if (!monthMenu) return;
        monthMenu.hidden = false;
        if (monthTrigger) monthTrigger.setAttribute('aria-expanded', 'true');
        // Auto-scroll so the selected month is ~2nd from the top of the viewport
        // (e.g. Sep shows Aug/Sep/Oct/Nov/Dec), without scrolling the page.
        var items = monthMenu.children;
        for (var i = 0; i < items.length; i++) {
            if (items[i].getAttribute('data-ym') === funnelMonth) {
                var h = items[i].offsetHeight || 33;
                var maxScroll = monthMenu.scrollHeight - monthMenu.clientHeight;
                monthMenu.scrollTop = Math.max(0, Math.min((i - 1) * h, maxScroll));
                break;
            }
        }
    }

    function closeMonthMenu() {
        if (!monthMenu) return;
        monthMenu.hidden = true;
        if (monthTrigger) monthTrigger.setAttribute('aria-expanded', 'false');
    }

    function renderFunnel(monthLabel, stages, isCurrent) {
        if (!funnelWrap) return;
        if (subtitleEl) {
            subtitleEl.textContent = isCurrent
                ? 'Current month recruitment funnel.'
                : 'Recruitment funnel for the selected month.';
        }
        var total = stages.reduce(function (acc, s) { return acc + s.count; }, 0);
        if (total === 0) {
            funnelWrap.innerHTML = '<div class="recruit-funnel-empty">No recruitment activity this month</div>';
            return;
        }
        var max = Math.max.apply(null, stages.map(function (s) { return s.count; })) || 1;
        var rows = '';
        stages.forEach(function (s, i) {
            var px = Math.max(2, Math.round((s.count / max) * BAR_MAX_PX));
            rows += '<div class="recruit-funnel-row">' +
                    '<span class="recruit-funnel-label">' + esc(s.name) + '</span>' +
                    '<span class="recruit-funnel-bars"><span class="recruit-funnel-fill' + (s.count === 0 ? ' zero' : '') + '" ' +
                    'style="width:' + px + 'px;background:' + FUNNEL_COLORS[i % FUNNEL_COLORS.length] + '"></span></span>' +
                    '<span class="recruit-funnel-count">' + fmtNum(s.count) + '</span>' +
                    '</div>';
        });
        funnelWrap.innerHTML = rows;
    }

    function loadFunnel() {
        if (!funnelWrap) return;
        funnelWrap.innerHTML = '<div class="recruit-funnel-loading">Loading&hellip;</div>';
        var qs = 'view=funnel';
        if (funnelMonth) { qs += '&month=' + encodeURIComponent(funnelMonth); }
        if (posSel && posSel.value) { qs += '&position_id=' + encodeURIComponent(posSel.value); }
        fetch(BASE + '/modules/dashboard/stats_data.php?' + qs, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) throw new Error('funnel status ' + r.status);
            return r.json();
        }).then(function (data) {
            if (!data.ok || !data.stage) {
                funnelWrap.innerHTML = '<div class="recruit-funnel-empty">Unable to load recruitment data.</div>';
                return;
            }
            funnelMonth = data.month || funnelMonth;
            if (monthLabelEl) { monthLabelEl.textContent = data.month_label || formatMonthShort(funnelMonth); }
            buildMonthMenu(funnelMonth);
            renderFunnel(data.month_label, data.stage, data.is_current);
        }).catch(function () {
            funnelWrap.innerHTML = '<div class="recruit-funnel-empty">Unable to load recruitment data.</div>';
        });
    }

    // Draw a lightweight, responsive SVG bar chart of total applications.
    // Single clear light-purple series so the counts are easy to read.
    function renderChart(labels, values) {
        var W = 800, H = 340, PAD_L = 44, PAD_R = 12, PAD_T = 28, PAD_B = 42;
        var n = values.length;
        if (!n) { emptyHandler(); return; }

        var maxV = 1;
        for (var i = 0; i < n; i++) if (values[i] > maxV) maxV = values[i];
        var niceMax = niceCeil(maxV);
        var innerW = W - PAD_L - PAD_R;
        var innerH = H - PAD_T - PAD_B;
        var baseY  = PAD_T + innerH;

        function y(v) { return PAD_T + innerH - (v / niceMax) * innerH; }

        // Bars fill the slot — wide and clearly visible.
        var slot = innerW / n;
        var gap = n > 12 ? 6 : 12;
        var barW = Math.max(6, Math.min(56, slot - gap));
        var radius = Math.min(8, barW * 0.2);

        // Subtle horizontal grid + Y-axis labels.
        var grid = '';
        for (var g = 0; g <= 4; g++) {
            var val = Math.round((niceMax * g) / 4);
            var gy = y(val);
            grid += '<line x1="' + PAD_L + '" y1="' + gy.toFixed(1) + '" x2="' + (W - PAD_R) + '" y2="' + gy.toFixed(1) +
                    '" stroke="' + GRID_COLOR + '" stroke-width="1"/>';
            grid += '<text x="' + (PAD_L - 8) + '" y="' + (gy + 4) + '" text-anchor="end" font-size="10" fill="' + TEXT_COLOR + '">' + val + '</text>';
        }

        // X-axis labels — thin out if too many bars to stay readable.
        var step = Math.ceil(n / 12);
        var xlabels = '';
        for (var i = 0; i < n; i++) {
            var cx = PAD_L + slot * i + slot / 2;
            if (i % step === 0 || i === n - 1) {
                xlabels += '<text x="' + cx.toFixed(1) + '" y="' + (H - 24) + '" text-anchor="middle" font-size="10.5" fill="' + AXIS_TXT + '">' + esc(labels[i]) + '</text>';
            }
        }

        // Bars (rounded rectangles) in light purple + transparent hover hit-areas.
        var bars = '';
        for (var i = 0; i < n; i++) {
            var v  = values[i];
            var cx = PAD_L + slot * i + slot / 2;
            var x0 = cx - barW / 2;
            var y0 = y(v);
            var h  = Math.max(0, baseY - y0);
            var labelY = Math.max(y0 - 7, PAD_T + 8);
            bars += '<rect class="stats-bar" id="b' + i + '" x="' + x0.toFixed(1) + '" y="' + y0.toFixed(1) + '" width="' + barW.toFixed(1) + '" ' +
                    'height="' + h.toFixed(1) + '" rx="' + radius.toFixed(1) + '"' +
                    ' fill="' + CHART_COLOR + '" fill-opacity="' + BAR_OPACITY + '"' +
                    ' data-label="' + esc(labels[i]) + '" data-val="' + v + '"/>';
            if (v > 0) {
                bars += '<text x="' + cx.toFixed(1) + '" y="' + labelY.toFixed(1) + '" text-anchor="middle" font-size="11" font-weight="700" fill="#ffffff">' + fmtNum(v) + '</text>';
            }
            bars += '<rect class="stats-hit" data-bid="b' + i + '" x="' + x0.toFixed(1) + '" y="' + PAD_T + '" width="' + barW.toFixed(1) + '" height="' + innerH.toFixed(1) + '" fill="transparent"/>';
        }

        var tooltip = '<div class="stats-tooltip" id="statsTooltip" hidden></div>';

        wrap.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Applicant statistics by period bar chart" preserveAspectRatio="xMidYMid meet">' +
            grid + bars + xlabels + '</svg>' + tooltip;

        // Tooltip on hover (the count is already labelled on the bar).
        var tt = document.getElementById('statsTooltip');
        if (!tt) return;
        var hitEls = wrap.querySelectorAll('.stats-hit');
        hitEls.forEach(function (hit) {
            var bid   = hit.getAttribute('data-bid');
            var myBar = document.getElementById(bid);
            var label = myBar ? myBar.getAttribute('data-label') : '';
            var val   = myBar ? myBar.getAttribute('data-val') : '0';
            hit.addEventListener('mouseenter', function () {
                tt.innerHTML = '<div><strong>' + esc(label) + '</strong></div><span>' + fmtNum(val) + ' applications</span>';
                tt.hidden = false;
                if (myBar) {
                    myBar.setAttribute('data-saved-opacity', myBar.getAttribute('fill-opacity'));
                    myBar.setAttribute('fill-opacity', '1');
                }
            });
            hit.addEventListener('mousemove', function (e) {
                var rect = wrap.getBoundingClientRect();
                tt.style.left = (e.clientX - rect.left) + 'px';
                tt.style.top = (e.clientY - rect.top) + 'px';
            });
            hit.addEventListener('mouseleave', function () {
                tt.hidden = true;
                if (myBar) {
                    var savedOp = myBar.getAttribute('data-saved-opacity');
                    if (savedOp) { myBar.setAttribute('fill-opacity', savedOp); myBar.removeAttribute('data-saved-opacity'); }
                }
            });
        });
    }

    function niceCeil(v) {
        if (v <= 1) return 1;
        var mag = Math.pow(10, Math.floor(Math.log10(v)));
        var norm = v / mag;
        var nice = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10;
        return nice * mag;
    }

    // Fetch data and re-render. Uses the same session as the page (same-origin,
    // no separate API auth needed — the endpoint enforces HR/Manager locally).
    function load() {
        state.hidden = true;
        wrap.innerHTML = '<div class="stats-loading">Loading statistics&hellip;</div>';
        var qs = 'period=' + encodeURIComponent(periodSel.value) +
                 '&year=' + encodeURIComponent(yearSel.value) +
                 '&position_id=' + encodeURIComponent(posSel.value);
        fetch(BASE + '/modules/dashboard/stats_data.php?' + qs, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) throw new Error('stats status ' + r.status);
            return r.json();
        }).then(function (data) {
            if (!data.ok) { errorHandler(); return; }
            // Reset to "All Positions" (and sync the funnel) if the selected
            // job posting was deleted while the page was open.
            var deleted = fillPositions(data.positions);
            fillYears(data.range && data.range.min, data.range && data.range.max);
            yearWrap.style.visibility = (data.period === 'yearly') ? 'hidden' : '';
            renderInsights(data.peak);
            var funnels = normalizeSeries(data);
            var anyVal = funnels.some(function (f) { return f.values.some(function (v) { return v > 0; }); });
            if (!funnels.length || !anyVal) { emptyHandler(); return; }
            state.hidden = true;
            renderChart(data.labels, funnels[0].values);
            if (deleted) { loadFunnel(); }
        }).catch(function () {
            errorHandler();
        });
    }

    // Update the two compact insight cards from the endpoint's peak data.
    // "No data" (never a misleading 0) when no peak record matches the filters.
    function renderInsights(peak) {
        if (!peak) peak = {};
        var peakMonth = peak.month || null;
        var topJob    = peak.position || null;

        var mVal = document.getElementById('insightPeakMonth');
        var mSub = document.getElementById('insightPeakMonthSub');
        if (peakMonth && peakMonth.count > 0) {
            mVal.textContent = formatMonthLabel(peakMonth.label);
            mSub.textContent = fmtNum(peakMonth.count) + ' Applications';
        } else {
            mVal.textContent = 'No data';
            mSub.textContent = '';
        }

        var jVal = document.getElementById('insightTopJob');
        var jSub = document.getElementById('insightTopJobSub');
        if (topJob && topJob.count > 0 && topJob.label) {
            jVal.textContent = topJob.label;
            jSub.textContent = fmtNum(topJob.count) + ' Applications';
        } else {
            jVal.textContent = 'No data';
            jSub.textContent = '';
        }
    }

    // Convert a "Y-m" server label (e.g. "2026-09") to "September 2026".
    function formatMonthLabel(label) {
        if (!label) return 'No data';
        var parts = String(label).split('-');
        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10);
        if (isNaN(y) || isNaN(m)) return 'No data';
        var names = ['January','February','March','April','May','June','July',
                     'August','September','October','November','December'];
        var name = names[(m - 1) % 12];
        return name + ' ' + y;
    }

    filters.addEventListener('change', load);
    if (posSel) { posSel.addEventListener('change', loadFunnel); }

    // Custom month dropdown (click to open/close, click outside or Esc to close,
    // clicking a month selects it and reloads the funnel).
    if (monthTrigger && monthMenu) {
        monthTrigger.addEventListener('click', function () {
            if (monthMenu.hidden) { openMonthMenu(); } else { closeMonthMenu(); }
        });
        monthMenu.addEventListener('click', function (e) {
            var t = e.target;
            var li = t && t.nodeType === 1 && t.closest ? t.closest('li[data-ym]') : null;
            if (!li) return;
            var ym = li.getAttribute('data-ym');
            funnelMonth = ym;
            if (monthLabelEl) { monthLabelEl.textContent = formatMonthShort(ym); }
            buildMonthMenu(ym);
            closeMonthMenu();
            loadFunnel();
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('#recruitMonthWrap')) return;
            closeMonthMenu();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMonthMenu();
        });
    }

    // Initial load with sensible defaults (Monthly, latest year, all jobs).
    // Set the year dropdown to "latest" by pre-selecting after fetching range
    // automatically — the server clamps year to the range, so just pass blank.
    yearSel.innerHTML = '<option value="">…</option>';
    load();
    loadFunnel();

    // Re-fetch the latest job-position list when the user returns to the page
    // (e.g. after an HR/Admin renames a posting in another tab). Light debounce,
    // no polling. load()/loadFunnel() preserve the current selection by id, so
    // a rename shows the new title while keeping the same job selected.
    var lastRefresh = Date.now();
    function refreshStatsOnVisible() {
        if (document.hidden) return;
        var now = Date.now();
        if (now - lastRefresh < 2000) return;
        lastRefresh = now;
        load();
        loadFunnel();
    }
    document.addEventListener('visibilitychange', refreshStatsOnVisible);
    window.addEventListener('focus', refreshStatsOnVisible);
})();
</script>

<?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
?>
