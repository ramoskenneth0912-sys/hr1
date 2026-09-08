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
 *   period   : daily | monthly | yearly          (default: monthly)
 *   year     : 4-digit year, clamped to data range (default: latest year)
 *   position : '' (All Jobs) or an existing position name (default: '')
 *
 * All aggregation is done in SQL with COUNT + GROUP BY over applicants,
 * grouped by the actual application submission date (applicants.applied_date).
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
    // Union of job_postings titles and the free-text position_applied values so
    // a position is listable if it is posted and/or has applicants.
    $positionRows = $pdo->query(
        "SELECT title FROM job_postings WHERE title IS NOT NULL AND TRIM(title) <> ''
         UNION
         SELECT position_applied FROM applicants
         WHERE position_applied IS NOT NULL AND TRIM(position_applied) <> ''
         ORDER BY title ASC"
    )->fetchAll();
    $positions = array_values(array_filter(array_map('trim', array_column($positionRows, 'title'))));

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

    $position = trim((string) ($_GET['position'] ?? ''));
    if ($position !== '' && !in_array($position, $positions, true)) {
        $position = '';
    }

    // ---- Build the WHERE clause (all values bound / whitelisted) -----------
    $where  = ' WHERE 1=1';
    $params = [];
    if ($period !== 'yearly') {
        $where .= ' AND YEAR(applied_date) = ?';
        $params[] = $year;
    }
    if ($position !== '') {
        $where .= ' AND position_applied = ?';
        $params[] = $position;
    }

    // ---- Aggregated series ------------------------------------------------
    switch ($period) {
        case 'daily':
            $sql = "SELECT applied_date AS bucket, COUNT(*) AS cnt
                    FROM applicants $where GROUP BY applied_date ORDER BY applied_date ASC";
            $format = 'Y-m-d';
            break;
        case 'yearly':
            $sql = "SELECT YEAR(applied_date) AS bucket, COUNT(*) AS cnt
                    FROM applicants $where GROUP BY YEAR(applied_date) ORDER BY bucket ASC";
            $format = 'Y';
            break;
        default: // monthly
            $sql = "SELECT DATE_FORMAT(applied_date, '%Y-%m') AS bucket, COUNT(*) AS cnt
                    FROM applicants $where GROUP BY DATE_FORMAT(applied_date, '%Y-%m') ORDER BY bucket ASC";
            $format = 'Y-m';
            break;
    }
    $stmt  = $pdo->prepare($sql);
    $stmt->execute($params);
    $seriesRows = $stmt->fetchAll();

    [$labels, $values] = buildSeries($seriesRows, $period, $format, $year, count($params) > 0 && $period !== 'yearly');

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
        if ($position !== '') { $pdWhere .= ' AND position_applied = ?'; $pdParams[] = $position; }
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
        if ($position !== '') { $pmWhere .= ' AND position_applied = ?'; $pmParams[] = $position; }
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

    // Most applied position (respect the position filter scope for "total")
    $mpWhere  = ' WHERE 1=1';
    $mpParams = [];
    if ($period !== 'yearly') { $mpWhere .= ' AND YEAR(applied_date) = ?'; $mpParams[] = $year; }
    $mpr = $pdo->prepare(
        "SELECT position_applied, COUNT(*) AS c FROM applicants $mpWhere
         GROUP BY position_applied ORDER BY COUNT(*) DESC, position_applied ASC LIMIT 1"
    );
    $mpr->execute($mpParams);
    $mp = $mpr->fetch();

    echo json_encode([
        'ok'        => true,
        'period'    => $period,
        'year'      => $year,
        'position'  => $position,
        'positions' => $positions,
        'range'     => ['min' => $minDate, 'max' => $maxDate],
        'total'     => $total,
        'labels'    => $labels,
        'values'    => $values,
        'peak'      => [
            'day'    => $peakDay['label'] ? ['label' => $peakDay['label'], 'count' => $peakDay['count']] : null,
            'month'  => $peakMonth['label'] ? ['label' => $peakMonth['label'], 'count' => $peakMonth['count']] : null,
            'position' => $mp ? ['label' => $mp['position_applied'], 'count' => (int) $mp['c']] : null,
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
function buildSeries(array $rows, string $period, string $format, int $year, bool $inRange): array
{
    $map = [];
    foreach ($rows as $r) {
        $map[(string) $r['bucket']] = (int) $r['cnt'];
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
    $values = [];
    foreach ($keys as $k) {
        $labels[] = formatLabel($k, $period);
        $values[] = $map[$k] ?? 0;
    }
    return [$labels, $values];
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
