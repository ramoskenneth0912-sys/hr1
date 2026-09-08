<?php
/**
 * CLI remediation: unlink clearly-wrong cross-person resume associations.
 *
 * The following demo applicants were seeded with ANOTHER named person's resume
 * that is unrelated to their applied position (checked against SHA-256 content
 * grouping + position requirements). Per the data-quality directive, a wrong
 * resume must not be used to compute an AI score for a different applicant, so
 * we remove the association (resume_path -> NULL) and let the screening report
 * the applicant honestly as lacking a resume (Unavailable) rather than scoring
 * them against an unrelated person's resume.
 *
 *   #153 ken kenchu        (guard, job 40)  <- was Maria Santos MARKETING resume
 *   #159 janice ann        (janitor, job 41)<- was Maria Santos MARKETING resume
 *   #161 christoper marquez(maintenance, job 42)<- was Alexander Reyes OPS resume
 *
 * No resume content is created, deleted, or fabricated. No applicants removed.
 * The 34 demo applicants that intentionally share Kenneth Ramos' resume are NOT
 * touched.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/../config/database.php';

$targets = [153, 159, 161];

echo "=== BEFORE: associations to be corrected ===\n";
foreach ($targets as $id) {
    $a = db()->prepare('SELECT id, first_name, last_name, position_applied, job_posting_id, resume_path FROM applicants WHERE id = ?');
    $a->execute([$id]);
    $row = $a->fetch();
    if ($row) {
        printf("  #%d %-18s pos='%s' jobId=%s resume='%s'\n",
            $row['id'], trim($row['first_name'] . ' ' . $row['last_name']),
            $row['position_applied'], var_export($row['job_posting_id'], true), $row['resume_path']);
    }
}

$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun) {
    echo "\n[DRY-RUN] no changes made.\n";
    exit(0);
}

echo "\n=== AFTER: unlinking wrong resumes ===\n";
foreach ($targets as $id) {
    $a = db()->prepare('SELECT id, first_name, last_name, resume_path FROM applicants WHERE id = ?');
    $a->execute([$id]);
    $row = $a->fetch();
    if (!$row) { continue; }
    $old = $row['resume_path'];
    db()->prepare('UPDATE applicants SET resume_path = NULL WHERE id = ?')->execute([$id]);
    printf("  #%d %-18s resume_path: '%s' -> NULL\n", $row['id'], trim($row['first_name'] . ' ' . $row['last_name']), $old);
}

echo "\nDone.\n";
