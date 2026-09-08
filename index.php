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

<!-- ===================== Applicant Statistics ===================== -->
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

    <div class="stats-summary" id="statsSummary" hidden></div>

    <div class="stats-chart-wrap" id="statsChartWrap"></div>

    <div class="stats-state" id="statsState" hidden></div>
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
.stats-summary { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem; }
.stats-summary-item {
    flex: 1 1 160px; background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: .75rem 1rem;
}
.stats-summary-item .stats-summary-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; }
.stats-summary-item .stats-summary-value { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin-top: .15rem; }
.stats-summary-item .stats-summary-sub { font-size: .78rem; color: var(--muted); }
.stats-chart-wrap { position: relative; width: 100%; }
.stats-chart-wrap svg { display: block; width: 100%; height: auto; overflow: visible; }
.stats-chart-wrap .stats-bar { transition: fill .15s ease; }
.stats-bar-value {
    position: absolute; z-index: 11; pointer-events: none; transform: translateX(-50%);
    font-size: .78rem; font-weight: 700; color: var(--purple-dark);
}
.stats-bar-value[hidden] { display: none; }
.stats-state { padding: 2rem 1rem; text-align: center; color: var(--muted); font-size: .9rem; background: var(--bg); border: 1px dashed var(--border); border-radius: var(--radius-sm); }
.stats-tooltip {
    position: absolute; z-index: 10; pointer-events: none; transform: translate(-50%, -130%);
    background: var(--text-dark); color: #fff; border-radius: 6px; padding: .35rem .6rem;
    font-size: .72rem; line-height: 1.35; white-space: nowrap; box-shadow: 0 8px 20px -8px rgba(27,37,89,.5);
}
.stats-tooltip strong { display: block; font-weight: 600; }
.stats-tooltip[hidden] { display: none; }

</style>

<script>
(function () {
    var BASE = '<?= e(BASE_URL) ?>';
    var wrap  = document.getElementById('statsChartWrap');
    var state = document.getElementById('statsState');
    var summary = document.getElementById('statsSummary');
    var filters = document.getElementById('statsFilters');
    if (!wrap || !filters) return;

    var periodSel  = document.getElementById('statsPeriod');
    var yearSel    = document.getElementById('statsYear');
    var posSel     = document.getElementById('statsPosition');
    var yearWrap   = document.getElementById('statsYearWrap');
    var COLOR      = '#A855F7';   // soft purple for bars (applied with ~75% opacity)
    var PEAK_COLOR = '#9333EA';   // slightly stronger purple for peak bar
    var HIGHLIGHT_COLOR = '#C084FC'; // lighter purple on hover
    var GRID_COLOR = '#E9EDF7';   // --border
    var TEXT_COLOR = '#A3AED0';   // --muted
    var AXIS_TXT   = '#2B3674';   // --text

    // Populate the job-position dropdown dynamically from the data response.
    function fillPositions(posList) {
        var opts = '<option value="">All Jobs</option>';
        (posList || []).forEach(function (p) {
            opts += '<option value="' + esc(p) + '">' + esc(p) + '</option>';
        });
        posSel.innerHTML = opts;
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

    function fmtDayLabel(l) {
        // label may be a plain date string in YYYY-MM-DD (from server) — keep as-is
        return String(l || '');
    }

    function monthName(m) {
        var names = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var n = parseInt(m, 10);
        return names[(n - 1)] || String(m);
    }

    function emptyHandler() {
        wrap.innerHTML = '';
        summary.hidden = true;
        state.hidden = false;
        state.textContent = 'No application data available for the selected period.';
    }

    function errorHandler() {
        wrap.innerHTML = '';
        summary.hidden = true;
        state.hidden = false;
        state.textContent = 'Unable to load applicant statistics. Please try again later.';
    }

    // Render the summary metrics.
    function renderSummary(data) {
        var posSelVal = data.position;
        var items = [];
        items.push({ label: 'Total Applications', value: fmtNum(data.total), sub: '' });
        if (data.peak.day) {
            items.push({ label: 'Peak Day', value: fmtDayLabel(data.peak.day.label), sub: fmtNum(data.peak.day.count) + ' applications' });
        }
        if (data.peak.month) {
            items.push({ label: 'Peak Month', value: monthName(data.peak.month.label.slice(5, 7)), sub: fmtNum(data.peak.month.count) + ' applications' });
        }
        if (data.peak.position && data.peak.position.label) {
            items.push({
                label: 'Most Applied Position',
                value: data.peak.position.label,
                sub: (data.peak.position.count != null ? fmtNum(data.peak.position.count) + ' applications' : '')
            });
        }
        var html = '';
        items.forEach(function (it) {
            html += '<div class="stats-summary-item">' +
                    '<div class="stats-summary-label">' + esc(it.label) + '</div>' +
                    '<div class="stats-summary-value">' + esc(it.value) + '</div>' +
                    (it.sub ? '<div class="stats-summary-sub">' + esc(it.sub) + '</div>' : '') +
                    '</div>';
        });
        summary.innerHTML = html;
        summary.hidden = false;
    }

    // Draw a lightweight, responsive SVG vertical bar chart with a simple tooltip.
    function renderChart(labels, values) {
        var W = 800, H = 300, PAD_L = 44, PAD_R = 12, PAD_T = 22, PAD_B = 40;
        var n = values.length;
        if (!n) { emptyHandler(); return; }

        var maxV = 1;
        for (var i = 0; i < n; i++) if (values[i] > maxV) maxV = values[i];
        var niceMax = niceCeil(maxV);
        var innerW = W - PAD_L - PAD_R;
        var innerH = H - PAD_T - PAD_B;
        var baseY  = PAD_T + innerH;

        function y(v) { return PAD_T + innerH - (v / niceMax) * innerH; }

        // Bar geometry: constant gap between bars, bars fill remaining width.
        var gap = n > 12 ? 6 : 10;
        var slot = innerW / n;
        var barW = Math.max(4, Math.min(48, slot - gap));
        var radius = Math.min(6, barW * 0.25);

        // Subtle horizontal grid + Y-axis labels.
        var grid = '';
        for (var g = 0; g <= 4; g++) {
            var val = Math.round((niceMax * g) / 4);
            var gy = y(val);
            grid += '<line x1="' + PAD_L + '" y1="' + gy + '" x2="' + (W - PAD_R) + '" y2="' + gy +
                    '" stroke="' + GRID_COLOR + '" stroke-width="1"/>';
            grid += '<text x="' + (PAD_L - 8) + '" y="' + (gy + 4) + '" text-anchor="end" font-size="10" fill="' + TEXT_COLOR + '">' + val + '</text>';
        }

        // X-axis labels — thin out if too many bars to stay readable.
        var step = Math.ceil(n / 12);
        var xlabels = '';
        for (var i = 0; i < n; i++) {
            var cx = PAD_L + slot * i + slot / 2;
            if (i % step === 0 || i === n - 1) {
                xlabels += '<text x="' + cx.toFixed(1) + '" y="' + (H - 22) + '" text-anchor="middle" font-size="10" fill="' + AXIS_TXT + '">' + esc(labels[i]) + '</text>';
            }
        }

        // Bars (rounded rectangles). Each bar is a transparent hit-area on top so
        // the whole column is hoverable; the visible fill carries the color.
        var bars = '';
        var peakIdx = -1, peakVal = -1;
        for (var i = 0; i < n; i++) {
            if (values[i] > peakVal) { peakVal = values[i]; peakIdx = i; }
        }
        for (var i = 0; i < n; i++) {
            var cx = PAD_L + slot * i + slot / 2;
            var x0 = cx - barW / 2;
            var v  = values[i];
            var y0 = y(v);
            var h  = Math.max(0, baseY - y0);
            var fill = i === peakIdx ? PEAK_COLOR : COLOR;
            bars += '<rect class="stats-bar" x="' + x0.toFixed(1) + '" y="' + y0.toFixed(1) + '" width="' + barW.toFixed(1) + '" ' +
                    'height="' + h.toFixed(1) + '" rx="' + radius.toFixed(1) + '"' +
                    ' fill="' + fill + '" fill-opacity="0.75" data-label="' + esc(labels[i]) + '" data-val="' + v + '"' +
                    (v === 0 ? ' style="opacity:.28"' : '') + '/>';
            bars += '<rect class="stats-hit" x="' + x0.toFixed(1) + '" y="' + PAD_T + '" width="' + barW.toFixed(1) + '" height="' + innerH.toFixed(1) + '" fill="transparent" data-label="' + esc(labels[i]) + '" data-val="' + v + '" data-orig="' + fill + '"/>';
        }

        var tooltip = '<div class="stats-tooltip" id="statsTooltip" hidden></div>';
        var valueTag = '<div class="stats-bar-value" id="statsBarValue" hidden></div>';

        wrap.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Applicant statistics by period bar chart" preserveAspectRatio="xMidYMid meet">' +
            grid + bars + xlabels + '</svg>' + valueTag + tooltip;

        // Tooltip + on-hover value tag with bar highlight.
        var tt = document.getElementById('statsTooltip');
        var vt = document.getElementById('statsBarValue');
        if (!tt || !vt) return;
        var hitEls = wrap.querySelectorAll('.stats-hit');
        hitEls.forEach(function (hit) {
            var label  = hit.getAttribute('data-label');
            var val    = hit.getAttribute('data-val');
            var origF  = hit.getAttribute('data-orig');
            var myBar  = wrap.querySelector('.stats-bar[data-label="' + label + '"]');
            var valText = fmtNum(val) + ' applications';
            hit.addEventListener('mouseenter', function () {
                tt.innerHTML = '<div><strong>' + esc(label) + '</strong></div><span>' + valText + '</span>';
                tt.hidden = false;
                if (myBar) {
                    myBar.setAttribute('data-saved-fill', myBar.getAttribute('fill'));
                    myBar.setAttribute('data-saved-opacity', myBar.getAttribute('fill-opacity'));
                    myBar.setAttribute('fill', HIGHLIGHT_COLOR);
                    myBar.setAttribute('fill-opacity', '1');
                    var bb = myBar.getBoundingClientRect();
                    var rect = wrap.getBoundingClientRect();
                    vt.textContent = fmtNum(val);
                    vt.hidden = false;
                    vt.style.left = (bb.left - rect.left + bb.width / 2) + 'px';
                    vt.style.top = (bb.top - rect.top - 8) + 'px';
                }
            });
            hit.addEventListener('mousemove', function (e) {
                var rect = wrap.getBoundingClientRect();
                tt.style.left = (e.clientX - rect.left) + 'px';
                tt.style.top = (e.clientY - rect.top) + 'px';
            });
            hit.addEventListener('mouseleave', function () {
                tt.hidden = true;
                vt.hidden = true;
                if (myBar) {
                    var saved = myBar.getAttribute('data-saved-fill');
                    var savedOp = myBar.getAttribute('data-saved-opacity');
                    if (saved) { myBar.setAttribute('fill', saved); myBar.removeAttribute('data-saved-fill'); }
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
        wrap.innerHTML = '<div class="stats-state">Loading statistics…</div>';
        var qs = 'period=' + encodeURIComponent(periodSel.value) +
                 '&year=' + encodeURIComponent(yearSel.value) +
                 '&position=' + encodeURIComponent(posSel.value);
        fetch(BASE + '/modules/dashboard/stats_data.php?' + qs, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) throw new Error('stats status ' + r.status);
            return r.json();
        }).then(function (data) {
            if (!data.ok) { errorHandler(); return; }
            fillPositions(data.positions);
            fillYears(data.range && data.range.min, data.range && data.range.max);
            yearWrap.style.visibility = (data.period === 'yearly') ? 'hidden' : '';
            renderSummary(data);
            if (!data.values.length) { emptyHandler(); return; }
            state.hidden = true;
            renderChart(data.labels, data.values);
        }).catch(function () {
            errorHandler();
        });
    }

    filters.addEventListener('change', load);

    // Initial load with sensible defaults (Monthly, latest year, all jobs).
    // Set the year dropdown to "latest" by pre-selecting after fetching range
    // automatically — the server clamps year to the range, so just pass blank.
    yearSel.innerHTML = '<option value="">…</option>';
    load();
})();
</script>

<?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
?>
