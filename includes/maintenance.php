<?php
/**
 * Hybrid maintenance-mode engine (4 states).
 *
 *   OFF     — System fully available, no banner.
 *   NOTICE  — System fully available; a non-blocking banner informs visitors.
 *   LIMITED — Only explicitly selected modules are blocked (503 page); the
 *             rest of the system keeps working and shows a notice banner.
 *   FULL    — The whole system is down for everyone except HR admins, who
 *             keep full access so they can manage config and turn it off.
 *
 * Enforced centrally from a single-row config table (maintenance_settings):
 * this file is loaded by includes/functions.php, which every page in the app
 * requires — so the gate runs BEFORE any protected functionality executes.
 * CLI scripts, auth pages (MAINTENANCE_EXEMPT_PAGE) and the /api/v1 surface
 * (which enforces its own JSON 503) are exempt.
 *
 * Security properties:
 *   - No URL/query-parameter bypass: the decision is based on the executed
 *     script path (SCRIPT_NAME), not on user input.
 *   - Admin bypass is role-based only (session role === 'hr'), which is the
 *     same gate the Settings page uses (isAdmin()).
 *   - Maintenance messages are stored plain but always escaped on output.
 *   - All config changes are audited via security_log().
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Canonical registry of LIMITED-mode modules — only modules that actually
 * exist in this codebase. 'jobs' covers the public careers/apply pages.
 */
if (!defined('MAINTENANCE_MODULE_DEFS')) {
    define('MAINTENANCE_MODULE_DEFS', [
        'jobs'        => ['label' => 'Jobs (Public Careers & Apply)',   'path' => '/public/'],
        'recruitment' => ['label' => 'Recruitment',                     'path' => '/modules/recruitment/'],
        'applicants'  => ['label' => 'Applicants',                      'path' => '/modules/applicants/'],
        'employee'    => ['label' => 'Employee Portal (Employee)',      'path' => '/modules/employee/'],
        'onboarding'  => ['label' => 'Onboarding',                      'path' => '/modules/onboarding/'],
        'hcm'         => ['label' => 'Core HCM',                        'path' => '/modules/hcm/'],
        'records'     => ['label' => 'Records',                         'path' => '/modules/records/'],
        'applicant'   => ['label' => 'Applicant Portal',                'path' => '/modules/applicant/'],
        'users'       => ['label' => 'Users',                           'path' => '/modules/users/'],
        'settings'    => ['label' => 'Settings',                        'path' => '/modules/settings/'],
    ]);
}

/** Single-row maintenance config. Cached per request; DB failure = OFF. */
function maintenance_config(): ?array
{
    static $cfg = false;
    if ($cfg === false) {
        $cfg = null;
        try {
            $stmt = db()->prepare(
                'SELECT id, mode, message, start_at, end_at, selected_modules, updated_by, updated_at
                 FROM maintenance_settings WHERE id = 1 LIMIT 1'
            );
            $stmt->execute();
            $row = $stmt->fetch();
            $cfg = $row === false ? null : $row;
        } catch (Throwable $e) {
            $cfg = null;
        }
    }
    return $cfg;
}

/** Allowed mode values. */
function maintenance_valid_modes(): array
{
    return ['off', 'notice', 'limited', 'full'];
}

/** Stored (unresolved) mode. */
function maintenance_store_mode(): string
{
    $mode = strtolower((string) (maintenance_config()['mode'] ?? 'off'));
    return in_array($mode, maintenance_valid_modes(), true) ? $mode : 'off';
}

/**
 * Resolved mode, applying the optional schedule end:
 * maintenance starts immediately when saved and automatically turns OFF once
 * the request time passes end_at (start_at is no longer used by the UI).
 */
function maintenance_mode(): string
{
    $cfg = maintenance_config();
    $mode = maintenance_store_mode();
    if ($mode === 'off') {
        return 'off';
    }
    $now = time();
    $end   = !empty($cfg['end_at'])   ? strtotime((string) $cfg['end_at'])   : null;
    if ($end === false)   { $end = null; }
    if ($end !== null && $now > $end)     { return 'off'; }
    return $mode;
}

/** Whether maintenance is active at all (banner or block). */
function maintenance_is_active(): bool
{
    return maintenance_mode() !== 'off';
}

/** Admin-supplied message or a mode-appropriate default. */
function maintenance_message(): string
{
    $msg = trim((string) (maintenance_config()['message'] ?? ''));
    if ($msg !== '') {
        return $msg;
    }
    $defaults = [
        'notice'  => 'HR1 is currently undergoing scheduled maintenance. You may continue using the system normally.',
        'limited' => 'The Employee Portal is currently undergoing scheduled maintenance.',
        'full'    => 'The system is currently undergoing scheduled maintenance. Please check back shortly.',
    ];
    return $defaults[maintenance_mode()] ?? 'The system is temporarily unavailable. Please check back shortly.';
}

/** Slugs of modules currently blocked in LIMITED mode (validated to exist). */
function maintenance_selected_modules(): array
{
    static $sel = null;
    if ($sel === null) {
        $sel = [];
        $raw = (string) (maintenance_config()['selected_modules'] ?? '');
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            $sel = array_values(array_unique(array_filter(
                $arr,
                static fn ($m): bool => is_string($m)
                    && isset(MAINTENANCE_MODULE_DEFS[$m])
            )));
        }
    }
    return $sel;
}

/** Module of the current request, or null when none applies (global pages). */
function maintenance_current_module(): ?string
{
    static $mod = false;
    if ($mod !== false) {
        return $mod;
    }
    $mod = null;
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (MAINTENANCE_MODULE_DEFS as $slug => $def) {
        if (stripos($script, $def['path']) !== false) {
            $mod = $slug;
            break;
        }
    }
    return $mod;
}

/** Admin bypass — the true admin role only (hr), matching Settings::isAdmin. */
function maintenance_admin_bypass(): bool
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'hr';
}

/**
 * Requests that must never be HTML-blocked here:
 * CLI, auth pages (login/reset/logout) and API surfaces (own JSON 503).
 */
function maintenance_is_request_exempt(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    if (defined('MAINTENANCE_EXEMPT_PAGE')) {
        return true;
    }
    $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if (str_contains($uri, '/api/') || str_contains($uri, '/laravel-api/')) {
        return true;
    }
    return false;
}

/**
 * State of the current page for the present request:
 *   false     — maintenance off (nothing to do)
 *   'blocked' — show the 503 page and stop
 *   'available' — render normally (banner logic decides if a notice shows)
 */
function maintenance_page_state()
{
    $mode = maintenance_mode();
    if ($mode === 'off') {
        return false;
    }
    if (maintenance_admin_bypass()) {
        return 'available';
    }
    if ($mode === 'full') {
        return 'blocked';
    }
    if ($mode === 'limited') {
        $current = maintenance_current_module();
        $selected = maintenance_selected_modules();
        if ($current !== null && in_array($current, $selected, true)) {
            return 'blocked';
        }
        // Global pages that are not tied to a module (e.g. the /index.php
        // dashboard) are the Employee Portal landing page for employee-role
        // users, so they must be blocked too when that module is down.
        if ($current === null
            && in_array('employee', $selected, true)
            && ($_SESSION['user_role'] ?? '') === 'employee') {
            return 'blocked';
        }
    }
    return 'available';
}

/** Central gate: run once when functions.php loads (web requests only). */
function maintenance_check(): void
{
    if (maintenance_is_request_exempt()) {
        return;
    }
    if (maintenance_page_state() === 'blocked') {
        maintenance_block_html();
    }
}

/** Renders the standalone 503 page and stops the request (never leaks internals). */
function maintenance_block_html(): never
{
    http_response_code(503);
    $mode     = maintenance_mode();
    $title    = 'Maintenance in Progress';
    $message  = maintenance_message();
    $safeMsg  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeBase = htmlspecialchars((string) (BASE_URL ?? ''), ENT_QUOTES, 'UTF-8');
    $safeApp  = htmlspecialchars((string) (APP_NAME ?? 'System'), ENT_QUOTES, 'UTF-8');
    // Auth pages stay open during maintenance, so a simple Log In button in the
    // top-right keeps the way back in available; logged-in visitors get a
    // plain Go Back button to return to the public site instead.
    $topBtnLabel = empty($_SESSION['user_id']) ? 'Log In' : 'Go Back';
    $topBtnHref  = empty($_SESSION['user_id']) ? $safeBase . '/auth/login.php' : $safeBase . '/public/jobs.php';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> — <?= $safeApp ?></title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{min-height:100vh;display:flex;flex-direction:column;font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:#F4F6FB;color:#1d2130}
    .mmx-top{display:flex;align-items:center;justify-content:flex-end;gap:1rem;padding:.9rem 1.5rem}
    .mmx-btn{display:inline-flex;align-items:center;padding:.5rem 1.15rem;border:1px solid #d3d8e4;border-radius:9px;background:#fff;color:#2a2f3a;font-weight:600;font-size:.85rem;text-decoration:none;cursor:pointer;transition:background .15s ease}
    .mmx-btn:hover{background:#eceef4}
    .mmx-main{flex:1;display:flex;align-items:center;justify-content:center;padding:1rem 1.5rem 2.5rem}
    .mmx-card{max-width:520px;width:100%;text-align:center;animation:mmx-fade .4s ease}
    .mmx-badge{width:74px;height:74px;margin:0 auto 1.4rem;border-radius:22px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#FFB547,#F59E0B);box-shadow:0 14px 34px rgba(255,181,71,.4)}
    .mmx-badge svg{width:36px;height:36px}
    .mmx-card h1{font-size:1.7rem;font-weight:800;letter-spacing:-.02em}
    .mmx-card p{margin-top:.8rem;font-size:.98rem;line-height:1.6;color:#5a6172}
    .mmx-ref{position:fixed;bottom:1rem;left:0;right:0;text-align:center;font-size:.72rem;color:#9aa0b0}
    @keyframes mmx-fade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
</style>
</head>
<body>
    <header class="mmx-top">
        <a class="mmx-btn" href="<?= $topBtnHref ?>"><?= htmlspecialchars($topBtnLabel, ENT_QUOTES, 'UTF-8') ?></a>
    </header>
    <main class="mmx-main">
        <div class="mmx-card">
            <div class="mmx-badge" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= $safeMsg ?></p>
        </div>
    </main>
    <div class="mmx-ref"><?= $safeApp ?></div>
</body>
</html>
    <?php
    exit;
}

/**
 * Banner information for the shared partial, or null when no banner should
 * render on the current page.
 *
 *  NOTICE  — everyone sees it (announcement).
 *  LIMITED — shown on still-available pages (blocked pages get the 503);
 *            the message  lists the temporarily unavailable modules.
 *  FULL    — only HR admins reach rendered pages; they see the banner so the
 *            system-wide outage is unmistakable.
 */
function maintenance_banner_status(): ?array
{
    $mode  = maintenance_mode();
    $admin = maintenance_admin_bypass();

    if ($mode === 'off') {
        return null;
    }

    if ($mode === 'full') {
        if (!$admin) {
            return null;
        }
        return [
            'mode'           => 'full',
            'title'          => 'Full Maintenance Active',
            'message'        => maintenance_message(),
            'actionLabel'    => 'Open Settings',
            'actionUrl'      => BASE_URL . '/modules/settings/index.php#system',
            'dismissible'    => false,
        ];
    }

    if ($mode === 'limited') {
        $current = maintenance_current_module();
        $selected = maintenance_selected_modules();
        if ($current !== null && in_array($current, $selected, true)) {
            return null; // blocked page — the 503 already communicates this
        }
        $labels = array_map(
            static fn ($s): string => MAINTENANCE_MODULE_DEFS[$s]['label'],
            $selected
        );
        $message = maintenance_message();
        if ($labels !== [] && trim((string) (maintenance_config()['message'] ?? '')) === '') {
            $message = 'Temporarily unavailable: ' . implode(', ', $labels) . '.';
        }
        return [
            'mode'           => 'limited',
            'title'          => 'Module Maintenance',
            'message'        => $message,
            'actionLabel'    => $admin ? 'Open Settings' : null,
            'actionUrl'      => $admin ? BASE_URL . '/modules/settings/index.php#system' : null,
            'dismissible'    => false,
        ];
    }

    // notice
    return [
        'mode'           => 'notice',
        'title'          => 'Maintenance Notice',
        'message'        => maintenance_message(),
        'actionLabel'    => $admin ? 'Open Settings' : null,
        'actionUrl'      => $admin ? BASE_URL . '/modules/settings/index.php#system' : null,
        'dismissible'    => false,
    ];
}

/** First resource segment of the current /api/v1 request (lowercased). */
function maintenance_api_resource(): string
{
    static $res = false;
    if ($res === false) {
        $res = '';
        $path = function_exists('route_path') ? route_path() : '';
        $res = strtolower((string) explode('/', $path)[0]);
    }
    return $res;
}

/**
 * API-side enforcement, called from api/v1/index.php right after
 * Auth::authenticate(). Responds with a JSON 503 while maintenance is active.
 *
 *  FULL    — every endpoint is blocked except HR-admin callers.
 *  LIMITED — only the endpoints that map to blocked modules (jobs,
 *            applications) are blocked; system/admin endpoints stay reachable.
 *  NOTICE  — nothing blocked here (the banner is a website concern).
 */
function maintenance_api_block(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $mode = maintenance_mode();
    if ($mode === 'off') {
        return;
    }
    if (class_exists('Auth') && Auth::role() === 'hr') {
        return; // admin bypass — matches the website gate
    }

    if ($mode === 'full') {
        Response::error('The system is currently undergoing scheduled maintenance. Please try again later.', [], 503);
    }

    $resource = maintenance_api_resource();
    $selected = maintenance_selected_modules();
    if ($resource === 'jobs' && in_array('jobs', $selected, true)) {
        Response::error('Job listings are temporarily unavailable while maintenance is being performed.', [], 503);
    }
    if ($resource === 'applications'
        && (in_array('applicants', $selected, true) || in_array('applicant', $selected, true))) {
        Response::error('Applications are temporarily unavailable while maintenance is being performed.', [], 503);
    }
}