<?php
/**
 * APPLICANT STATISTICS DATA — read-only JSON endpoint backing the
 * "Applicant Statistics" chart on the HR/Admin dashboard.
 *
 * SECURITY
 *   - Requires an HR or Manager session (requireHRorManager → 403/redirect for
 *     anyone else).
 *   - Read-only: never changes state, so no CSRF token exchange is needed here,
 *     mirroring notification_poll.php. GET with an HttpOnly + SameSite=Lax
 *     session cookie cannot be driven cross-site, and the response is JSON only.
 *   - No user-supplied value is ever interpolated into SQL (all filters are
 *     bound parameters / whitelisted values).
 *   - No credentials, SQL errors, or stack traces are exposed: any failure logs
 *     to the server log and returns a generic message.
 *
 * FILTERS (all optional, validated server-side)
 *   period      : daily | monthly | yearly          (default: monthly)
 *   year        : 4-digit year, clamped to data range (default: latest year)
 *   position_id : '' (All Positions) or an existing job_postings.id (default: '')
 *
 * Job positions are always resolved from job_postings by their stable id and
 * shown with their *current* title, so renaming a posting (e.g. "Kargador" →
 * "Warehouse Associate") never leaves a stale name behind and never severs the
 * link to existing applicant statistics. applicants.job_posting_id is the
 * relationship used for filtering — never the editable title text.
 *
 * All aggregation is done in SQL with COUNT + GROUP BY over applicants,
 * grouped by the actual application submission date (applicants.applied_date).
 *
 * MODE
 *   view=funnel  : returns a current-MONTH-ONLY recruitment funnel
 *                  (Applied / Screen / Passed / Hired) instead of the
 *                  period series. The current month is resolved dynamically
 *                  from the application timezone (system_settings.timezone,
 *                  default Asia/Manila). This powers the "Recruitment
 *                  Overview" compact card. All inputs are ignored except for
 *                  the optional position filter.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_log.php';
requireHRorManager();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $pdo = db();

    // ---- Available job positions (dynamic, driven by the database) ---------
    // Source of truth is job_postings only (stable id + current title). The
    // old free-text applicants.position_applied values are intentionally NOT
    // included here — a renamed posting would otherwise resurface its old name.
    // Removed/soft-deleted postings (status = 'inactive') are excluded so they
    // never appear as an active option, while their applicant history remains
    // intact via job_posting_id.
    $positionRows = $pdo->query(
        "SELECT id, title FROM job_postings
         WHERE title IS NOT NULL AND TRIM(title) <> ''
           AND COALESCE(status, '') <> 'inactive'
         ORDER BY TRIM(title) ASC"
    )->fetchAll();
    $positions = [];
    $titleById = [];
    foreach ($positionRows as $r) {
        $id = (int) $r['id'];
        $title = trim($r['title']);
        $positions[] = ['id' => $id, 'title' => $title];
        $titleById[$id] = $title;
    }

    // ---- Data range (min/max application date) -----------------------------
    $range = $pdo->query('SELECT MIN(applied_date) mn, MAX(applied_date) mx FROM applicants')->fetch();
    $minDate = $range['mn'] ? date('Y-m-d', strtotime($range['mn'])) : null;
    $maxDate = $range['mx'] ? date('Y-m-d', strtotime($range['mx'])) : null;

    // ---- Validate / normalise filters --------------------------------------
    $period = strtolower((string) ($_GET['period'] ?? 'monthly'));
    if (!in_array($period, ['daily', 'monthly', 'yearly'], true)) {
        $period = 'monthly';
    }

    $defaultYear = $maxDate ? (int) date('Y', strtotime($maxDate)) : (int) date('Y');
    $yearInput   = (string) ($_GET['year'] ?? '');
    $year = $yearInput !== '' && preg_match('/^\d{4}$/', $yearInput) ? (int) $yearInput : $defaultYear;
    if ($minDate && $year < (int) date('Y', strtotime($minDate))) $year = (int) date('Y', strtotime($minDate));
    if ($maxDate && $year > (int) date('Y', strtotime($maxDate))) $year = (int) date('Y', strtotime($maxDate));

    $positionIdInput = trim((string) ($_GET['position_id'] ?? ''));
    $positionId = ($positionIdInput !== '' && ctype_digit($positionIdInput) && isset($titleById[(int) $positionIdInput]))
        ? (int) $positionIdInput
        : 0; // 0 = All Positions (also covers a posting that was deleted)

    // ---- Timezone (application-configured, default Asia/Manila) -----------
    $appTimezone = 'Asia/Manila';
    try {
        $tzRow = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'timezone' LIMIT 1")->fetch();
        if ($tzRow && !empty($tzRow['setting_value'])) {
            $appTimezone = $tzRow['setting_value'];
        }
    } catch (Throwable $e) {
        // fall back to the default above
    }

    // ---- Current-month-only recruitment funnel ----------------------------
    // Used by the "Recruitment Overview" compact card. The current month is
    // resolved dynamically from the application timezone — never hard-coded.
    $view = strtolower((string) ($_GET['view'] ?? ''));
    if ($view === 'funnel') {
        $tz = new DateTimeZone($appTimezone);
        $now = new DateTimeImmutable('now', $tz);

        // Optional month selector (YYYY-MM, e.g. "2026-09"). Defaults to the
        // current month — derived dynamically from the app timezone, never hard-coded.
        $monthInput = (string) ($_GET['month'] ?? '');
        $currentYm  = $now->format('Y-m');
        if ($monthInput !== '' && preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
            $selYm = $monthInput;
        } else {
            $selYm = $currentYm;
        }

        // Inclusive start of the selected month and exclusive start of the next.
        $monthStart = $selYm . '-01 00:00:00';
        $monthNext  = (new DateTimeImmutable($monthStart, $tz))->modify('first day of next month')->format('Y-m-01 00:00:00');
        $monthLabel = mb_strtoupper(date('M Y', strtotime($monthStart)));   // e.g. "SEP 2026"

        // Funnel stage → current-status set. Passed excludes the still-in-screening
        // group; Screen is intentionally omitted from the compact funnel.
        $stages = [
            [
                'name'   => 'Applied',
                'status' => 'ALL',
            ],
            [
                'name'   => 'Passed',
                'status' => "('shortlisted','passed_screening','accepted','interview','offered','hired')",
            ],
            [
                'name'   => 'Hired',
                'status' => "('hired')",
            ],
        ];

        $counts = [];
        foreach ($stages as $stage) {
            if ($stage['status'] === 'ALL') {
                // Applied = applications submitted during the current month.
                $sql = 'SELECT COUNT(*) FROM applicants
                        WHERE applied_date >= ? AND applied_date < ?';
                $paramsF = [$monthStart, $monthNext];
            } else {
                // Downstream stages: an applicant's current status reflects how
                // far they have progressed; updated_at is the best available
                // proxy for when that progression happened. No stage-history
                // table exists, so we attribute the stage to the month of the
                // record's last status change.
                $sql = 'SELECT COUNT(*) FROM applicants
                        WHERE status IN ' . $stage['status'] . '
                          AND updated_at >= ? AND updated_at < ?';
                $paramsF = [$monthStart, $monthNext];
            }
            if ($positionId !== 0) {
                $sql .= ' AND job_posting_id = ?';
                $paramsF[] = $positionId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($paramsF);
            $counts[] = [
                'name'  => $stage['name'],
                'count' => (int) $stmt->fetchColumn(),
            ];
        }

        echo json_encode([
            'ok'          => true,
            'view'        => 'funnel',
            'month'       => $selYm,
            'is_current'  => $selYm === $currentYm,
            'month_label' => $monthLabel,
            'month_start' => $monthStart,
            'stage'       => $counts,
        ]);
        exit;
    }

    // ---- Build the WHERE clause (all values bound / whitelisted) -----------
    $where  = ' WHERE 1=1';
    $params = [];
    if ($period !== 'yearly') {
        $where .= ' AND YEAR(applied_date) = ?';
        $params[] = $year;
    }
    if ($positionId !== 0) {
        $where .= ' AND job_posting_id = ?';
        $params[] = $positionId;
    }

    // ---- Aggregated series (recruitment funnel per period) ----------------
    // The funnel stages are cumulative — an applicant counts toward every stage
    // they have reached. 'applied' is the whole pool; the rest are status-based.
    $stageSql = [
        'applied' => '1 = 1',
        'screen'  => "status IN ('screening','shortlisted','passed_screening','accepted','interview','offered','hired')",
        'passed'  => "status IN ('shortlisted','passed_screening','accepted','interview','offered','hired')",
        'hired'   => "status IN ('hired')",
    ];

    switch ($period) {
        case 'daily':
            $bucketExpr = 'applied_date';
            $format = 'Y-m-d';
            break;
        case 'yearly':
            $bucketExpr = 'YEAR(applied_date)';
            $format = 'Y';
            break;
        default: // monthly
            $bucketExpr = "DATE_FORMAT(applied_date, '%Y-%m')";
            $format = 'Y-m';
            break;
    }

    $selectParts = ["$bucketExpr AS bucket"];
    foreach ($stageSql as $key => $expr) {
        $selectParts[] = "SUM(CASE WHEN $expr THEN 1 ELSE 0 END) AS $key";
    }
    $sql = 'SELECT ' . implode(', ', $selectParts) . " FROM applicants $where GROUP BY $bucketExpr ORDER BY bucket ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $seriesRows = $stmt->fetchAll();

    [$labels, $series] = buildSeries(
        $seriesRows, $period, $format, $year,
        count($params) > 0 && $period !== 'yearly',
        array_keys($stageSql)
    );

    // ---- Summary statistics ------------------------------------------------
    $totalSql = "SELECT COUNT(*) FROM applicants $where";
    $ts = $pdo->prepare($totalSql);
    $ts->execute($params);
    $total = (int) $ts->fetchColumn();

    // Peak day (always across full data, or within position filter when set)
    $peakDay = ['label' => null, 'count' => 0];
    if ($period !== 'yearly') {
        $pdWhere  = ' WHERE YEAR(applied_date) = ?';
        $pdParams = [$year];
        if ($positionId !== 0) { $pdWhere .= ' AND job_posting_id = ?'; $pdParams[] = $positionId; }
        $pdr = $pdo->prepare(
            "SELECT applied_date FROM applicants $pdWhere
             GROUP BY applied_date ORDER BY COUNT(*) DESC, applied_date ASC LIMIT 1"
        );
        $pdr->execute($pdParams);
        $pd = $pdr->fetch();
        if ($pd) {
            $cnt = $pdo->prepare(
                "SELECT COUNT(*) FROM applicants $pdWhere AND applied_date = ?"
            );
            $cnt->execute(array_merge($pdParams, [$pd['applied_date']]));
            $peakDay = ['label' => $pd['applied_date'], 'count' => (int) $cnt->fetchColumn()];
        }
    }

    // Peak month across the selected year
    $peakMonth = ['label' => null, 'count' => 0];
    if ($period !== 'yearly') {
        $pmWhere  = ' WHERE YEAR(applied_date) = ?';
        $pmParams = [$year];
        if ($positionId !== 0) { $pmWhere .= ' AND job_posting_id = ?'; $pmParams[] = $positionId; }
        $pmr = $pdo->prepare(
            "SELECT DATE_FORMAT(applied_date, '%Y-%m') AS m FROM applicants $pmWhere
             GROUP BY DATE_FORMAT(applied_date, '%Y-%m') ORDER BY COUNT(*) DESC, m ASC LIMIT 1"
        );
        $pmr->execute($pmParams);
        $pm = $pmr->fetch();
        if ($pm) {
            $cnt = $pdo->prepare(
                "SELECT COUNT(*) FROM applicants $pmWhere AND DATE_FORMAT(applied_date, '%Y-%m') = ?"
            );
            $cnt->execute(array_merge($pmParams, [$pm['m']]));
            $peakMonth = ['label' => $pm['m'], 'count' => (int) $cnt->fetchColumn()];
        }
    }

    // Most applied position (respect the position filter scope for "total").
    // The label uses the posting's CURRENT title via job_posting_id, falling
    // back to the recorded free-text only for applicants not linked to a posting.
    $mpWhere  = ' WHERE 1=1';
    $mpParams = [];
    if ($period !== 'yearly') { $mpWhere .= ' AND YEAR(a.applied_date) = ?'; $mpParams[] = $year; }
    if ($positionId !== 0) { $mpWhere .= ' AND a.job_posting_id = ?'; $mpParams[] = $positionId; }
    $mpr = $pdo->prepare(
        "SELECT COALESCE(j.title, a.position_applied) AS label, COUNT(*) AS c
         FROM applicants a
         LEFT JOIN job_postings j ON j.id = a.job_posting_id
         $mpWhere
         GROUP BY COALESCE(j.title, a.position_applied)
         ORDER BY COUNT(*) DESC, label ASC LIMIT 1"
    );
    $mpr->execute($mpParams);
    $mp = $mpr->fetch();

    echo json_encode([
        'ok'        => true,
        'period'    => $period,
        'year'      => $year,
        'position_id' => $positionId,
        'positions' => $positions,
        'range'     => ['min' => $minDate, 'max' => $maxDate],
        'total'     => $total,
        'labels'    => $labels,
        'values'    => $series['applied'],
        'series'    => [
            ['name' => 'Applied', 'values' => $series['applied']],
            ['name' => 'Screen',  'values' => $series['screen']],
            ['name' => 'Passed',  'values' => $series['passed']],
            ['name' => 'Hired',   'values' => $series['hired']],
        ],
        'peak'      => [
            'day'    => $peakDay['label'] ? ['label' => $peakDay['label'], 'count' => $peakDay['count']] : null,
            'month'  => $peakMonth['label'] ? ['label' => $peakMonth['label'], 'count' => $peakMonth['count']] : null,
            'position' => $mp ? ['label' => $mp['label'], 'count' => (int) $mp['c']] : null,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[applicant_stats] ' . $e->getMessage());
    try {
        securityLog('applicant_stats_error', 'Applicant statistics query failed: ' . $e->getMessage());
    } catch (Throwable $ignore) {
        // logging must never break the response
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unable to load applicant statistics. Please try again later.']);
}

/**
 * Turn aggregated rows into a continuous, gap-free series with human-readable
 * labels. Daily/monthly produce every bucket in range; yearly every year.
 */
function buildSeries(array $rows, string $period, string $format, int $year, bool $inRange, array $seriesKeys): array
{
    $map = [];
    foreach ($rows as $r) {
        $bucket = (string) $r['bucket'];
        $map[$bucket] = [];
        foreach ($seriesKeys as $key) {
            $map[$bucket][$key] = (int) ($r[$key] ?? 0);
        }
    }

    $keys = [];
    if ($period === 'daily') {
        $start = $year . '-01-01';
        $end   = $year . '-12-31';
        $cur   = $start;
        while ($cur <= $end) {
            $keys[] = $cur;
            $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
        }
    } elseif ($period === 'monthly') {
        for ($m = 1; $m <= 12; $m++) {
            $keys[] = $year . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        }
    } else { // yearly
        $years = array_keys($map);
        sort($years);
        $yMin = $years[0] ?? $year;
        $yMax = $years[count($years) - 1] ?? $year;
        for ($y = $yMin; $y <= $yMax; $y++) {
            $keys[] = (string) $y;
        }
    }

    $labels = [];
    $series = array_fill_keys($seriesKeys, []);
    foreach ($keys as $k) {
        $labels[] = formatLabel($k, $period);
        $row = $map[$k] ?? [];
        foreach ($seriesKeys as $key) {
            $series[$key][] = $row[$key] ?? 0;
        }
    }
    return [$labels, $series];
}

function formatLabel(string $key, string $period): string
{
    if ($period === 'daily') {
        return date('M j', strtotime($key));
    }
    if ($period === 'monthly') {
        $ts = strtotime($key . '-01');
        return date('M', $ts);
    }
    return $key;
}
