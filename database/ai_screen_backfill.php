<?php
/**
 * CLI-only tool: run the automatic AI resume screening for every applicant
 * who does not yet have an AI screening result (no ai_screening row).
 *
 * Uses the same autoScreenApplicant() path as the automatic trigger wired
 * into public/apply.php and the API: it NEVER changes the applicant's
 * recruitment status, job link, or any other applicant data — it only
 * writes the AI screening fields/result. Failed/unreadable resumes are
 * recorded as status = 'failed' (shown as "Unavailable" in the UI).
 *
 * Usage (from the project root):
 *   php database/ai_screen_backfill.php                 -> screen everyone lacking a result
 *   php database/ai_screen_backfill.php --dry-run       -> preview who would be screened
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai_screening.php';

$dryRun = in_array('--dry-run', $argv, true);

$stmt = db()->prepare(
    'SELECT a.id, a.applicant_no, a.first_name, a.last_name, a.position_applied
     FROM applicants a
     LEFT JOIN ai_screening s ON s.applicant_id = a.id
     WHERE s.id IS NULL
     ORDER BY a.id'
);
$stmt->execute();
$pending = $stmt->fetchAll();

$count = count($pending);
echo ($dryRun ? '[DRY-RUN] ' : '') . "Applicants without an AI screening result: {$count}\n";
echo str_repeat('-', 70) . "\n";

$analyzed = 0;
$failed = 0;
$skipped = 0;

foreach ($pending as $row) {
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    echo sprintf(
        "  #%-7d %-8s %-20s %s",
        (int) $row['id'],
        $row['applicant_no'],
        $name,
        $row['position_applied'] ?? ''
    );

    if ($dryRun) {
        echo "   [would screen]\n";
        continue;
    }

    $result = autoScreenApplicant((int) $row['id']);

    if (isset($result['success'])) {
        $analyzed++;
        echo "   -> {$result['recommendation']} ({$result['overall_score']}%)]\n";
    } elseif (isset($result['error'])) {
        $failed++;
        echo "   -> [no result: {$result['error']}]\n";
    } else {
        $skipped++;
        echo "   -> [skipped]\n";
    }
}

echo str_repeat('-', 70) . "\n";
if (!$dryRun) {
    echo "Done: {$analyzed} analyzed, {$failed} failed (Unavailable), {$skipped} skipped.\n";
} else {
    echo "Preview complete — re-run without --dry-run to screen these applicants.\n";
}

exit($failed > 0 && !$dryRun ? 0 : 0);