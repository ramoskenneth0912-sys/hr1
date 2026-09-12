<?php
$pageTitle = 'Dashboard';
$currentModule = 'dashboard';
$bodyClass = 'page-dashboard';
require_once __DIR__ . '/includes/auth.php';

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

    $pendingLeave = 0;
    $docCount = 0;
    if ($employeeId) {
        $s = db()->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'pending'");
        $s->execute([$employeeId]);
        $pendingLeave = (int) $s->fetchColumn();

        $s = db()->prepare('SELECT COUNT(*) FROM employee_documents WHERE employee_id = ?');
        $s->execute([$employeeId]);
        $docCount = (int) $s->fetchColumn();
    }

    // Unread notifications count for this account (summary only — list lives in Notifications).
    $s = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $s->execute([$uid]);
    $unreadNotifs = (int) $s->fetchColumn();
?>

<section class="welcome-banner fade-in-up">
    <div class="welcome-content">
        <p class="welcome-greeting"><?= e($greeting) ?>, <?= e(($user['first_name'] ?? '') ?: $user['username']) ?>!</p>
        <h1 class="welcome-title">My Workspace</h1>
        <p class="welcome-subtitle">Employee Self-Service — <?= e($user['job_title'] ?? 'Employee') ?><?= isset($user['department_name']) && $user['department_name'] ? ' · ' . e($user['department_name']) : '' ?></p>
    </div>
    <div class="welcome-status">
        <span class="status-label">System Status</span>
        <span class="status-value"><span class="live-dot"></span> Synced</span>
    </div>
</section>

<div class="stats-grid">
    <div class="kpi-card fade-in-up" style="animation-delay:.1s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-teal"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg></span>
        </div>
        <span class="kpi-value"><?= $pendingLeave ?></span>
        <span class="kpi-label">Pending Leave Requests</span>
        <a href="<?= BASE_URL ?>/modules/employee/leave.php" class="kpi-link">View Leave Requests &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.4s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-orange"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
        </div>
        <span class="kpi-value"><?= $docCount ?></span>
        <span class="kpi-label">My Documents</span>
        <a href="<?= BASE_URL ?>/modules/employee/documents.php" class="kpi-link">Open &rarr;</a>
    </div>
    <div class="kpi-card fade-in-up" style="animation-delay:.5s">
        <div class="kpi-top">
            <span class="kpi-icon kpi-icon-purple"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>
        </div>
        <span class="kpi-value"><?= $unreadNotifs ?></span>
        <span class="kpi-label">Unread Notifications</span>
        <a href="<?= BASE_URL ?>/modules/employee/notifications.php" class="kpi-link">View Notifications &rarr;</a>
    </div>
</div>

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
