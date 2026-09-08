<?php
/**
 * CLI-only data-quality validation tool for applicant resume / AI screening data.
 *
 * Detects and REPORTS (never auto-changes) the following data-quality issues
 * that can cause misleading AI match scores:
 *
 *   1. Duplicate resume content    — multiple applicants with byte-identical
 *      extracted resume text (grouped by SHA-256 content hash).
 *   2. Resume <-> position mismatch — applicant's resume content appears
 *      unrelated to the applied position / linked job requirements.
 *   3. Missing resume               — applicant record has no usable resume file.
 *   4. Invalid resume               — resume file exists but its text cannot be
 *      extracted/read.
 *   5. Placeholder job requirements — job postings with empty / gibberish
 *      requirement fields that would produce meaningless scores.
 *
 * Deterministic and read-only: it only prints a report. Run any remediation
 * (e.g. fixing associations or requirements) as a separate, audited step.
 *
 * Usage (from the project root):
 *   php database/check_data_quality.php                 -> full report
 *   php database/check_data_quality.php --brief         -> summary only
 *   php database/check_data_quality.php --json          -> JSON output
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai_screening.php';
require_once __DIR__ . '/../includes/functions.php';

$brief = in_array('--brief', $argv, true);
$json  = in_array('--json', $argv, true);

$root  = dirname(__DIR__); // project root (uploads lives here)
$today = date('Y-m-d');

/* ------------------------------------------------------------------ */
/* Load data                                                           */
/* ------------------------------------------------------------------ */
$applicants = db()->query(
    'SELECT a.id, a.applicant_no, a.first_name, a.last_name, a.email,
            a.position_applied, a.job_posting_id, a.resume_path, a.status,
            a.applied_date
     FROM applicants a
     ORDER BY a.id'
)->fetchAll();

$jobs = db()->query('SELECT * FROM job_postings ORDER BY id')->fetchAll();
$jobsById = [];
foreach ($jobs as $j) {
    $jobsById[(int) $j['id']] = $j;
}

/* ------------------------------------------------------------------ */
/* Helper: suspicious / placeholder requirement text                   */
/* ------------------------------------------------------------------ */
function isPlaceholderish(string $v): bool
{
    $v = mb_strtolower(trim($v));
    if ($v === '') {
        return true; // empty is a placeholder/incomplete signal
    }
    // Collapse to a single run of letters/words and drop punctuation so the
    // check applies to the meaning, not the formatting (e.g. "B.S., 2017").
    $compact = preg_replace('/[^a-z0-9\s]/', '', $v);
    $compact = preg_replace('/\s+/', ' ', trim($compact));

    // 1. A whole value that is keyboard-smash / repeated-noise gibberish.
    if (preg_match('/^(?:qwer+|wer+|asd+|qer+|aw+|w+|a+|x+)(?:[a-z]?)*$/i', $compact) === 1) {
        return true;
    }
    if (preg_match('/^([a-z])\1{2,}$/', $compact) === 1) {   // 'aaa', 'mmm'
        return true;
    }

    // 2. Very short, obviously non-job words (2 tokens max and tiny) with no
    //    spaces separated meaning — catch 'qwerqwer', 'masipag' *only* when it
    //    is the sole content and clearly junk is NOT true. We keep it narrow:
    //    single token that is pure consonant soup or has no real vowel pattern.
    if (preg_match('/^[bcdfghjklmnpqrstvwxz]{4,}$/i', $compact) === 1) {
        return true; // pure consonant mush
    }

    // 3. Explicit sentinel placeholders.
    return (bool) preg_match('/^(n\/?a|none|tbd|todo|xxx|lorem ipsum|dummy|placeholder|null)$/i', trim($v));
}

function requirementFieldStatus(array $job, string $field, string $label): array
{
    $val = trim((string) ($job[$field] ?? ''));
    $placeholder = isPlaceholderish($val);
    $status = $val === ''
        ? 'EMPTY'
        : ($placeholder ? 'PLACEHOLDER' : 'ok');
    return ['field' => $label, 'status' => $status, 'value' => $val];
}

/* ------------------------------------------------------------------ */
/* Compute per-applicant resume content hash                           */
/* ------------------------------------------------------------------ */
$report = [
    'generated'        => $today,
    'total_applicants' => count($applicants),
    'resume_sets'      => [],     // hash => ['count'=>n, 'applicants'=>[ids], 'snippet'=>...]
    'applicants'       => [],     // id => detail
];

$contentHash = []; // hash => list of applicant ids

foreach ($applicants as $a) {
    $id        = (int) $a['id'];
    $resume    = trim((string) $a['resume_path']);
    $abs       = $resume !== '' ? $root . '/' . ltrim($resume, '/') : '';
    $fileExists = $abs !== '' && is_file($abs);
    $text      = '';
    $hash      = null;
    $resumeState = 'NO_RESUME';

    if ($resume !== '' && $fileExists) {
        $text = extractResumeText($abs);
        if (trim($text) === '') {
            $resumeState = 'INVALID_RESUME';
        } else {
            $resumeState = 'ok';
            $hash = hash('sha256', $text);
        }
    } elseif ($resume !== '' && !$fileExists) {
        $resumeState = 'MISSING_FILE';
    }

    $report['applicants'][$id] = [
        'id'             => $id,
        'applicant_no'   => $a['applicant_no'],
        'name'           => trim($a['first_name'] . ' ' . $a['last_name']),
        'email'          => $a['email'],
        'position'       => $a['position_applied'],
        'job_id'         => $a['job_posting_id'] !== null ? (int) $a['job_posting_id'] : null,
        'status'         => $a['status'],
        'applied'        => $a['applied_date'],
        'resume_path'    => $resume,
        'resume_state'   => $resumeState,
        'content_hash'   => $hash,
        'text_length'    => strlen($text),
    ];

    if ($hash !== null) {
        $contentHash[$hash][] = $id;
    }
}

// Build resume-set summary
$resumeSets = [];
foreach ($contentHash as $hash => $ids) {
    $first = $report['applicants'][$ids[0]];
    $resumeSets[] = [
        'count'       => count($ids),
        'applicant_ids' => $ids,
        'snippet'     => mb_substr(
            preg_replace('/\s+/', ' ', (string) $report['applicants'][$ids[0]]['text_length'] ? extractResumeTextFn($root, $report['applicants'][$ids[0]]['resume_path']) : ''),
            0, 120
        ),
        'sample'      => trim($first['name']),
    ];
}
usort($resumeSets, fn($x, $y) => $y['count'] <=> $x['count']);
$report['resume_sets'] = $resumeSets;

/* ------------------------------------------------------------------ */
/* Resume <-> position mismatch heuristic                              */
/* ------------------------------------------------------------------ */
function resumeLooksRelated(string $resumeText, array $job): bool
{
    if (trim($resumeText) === '') {
        return false;
    }
    $nResume = normalizeText($resumeText);
    $candidate = [];
    foreach (['required_skills', 'qualifications', 'education_requirement', 'experience_requirement'] as $f) {
        $tokens = tokenize((string) ($job[$f] ?? ''));
        $candidate = array_merge($candidate, array_slice($tokens, 0, 6));
    }
    $candidate = array_values(array_unique(array_map('strtolower', $candidate)));
    $hits = 0;
    foreach ($candidate as $tok) {
        if (mb_strlen($tok) < 3) {
            continue;
        }
        if (mb_strpos($nResume, $tok) !== false) {
            $hits++;
        }
    }
    return $hits > 0;
}

$mismatches = [];
foreach ($report['applicants'] as $id => $a) {
    $job = $a['job_id'] !== null ? ($jobsById[$a['job_id']] ?? null) : null;
    if ($job === null || $a['resume_state'] !== 'ok') {
        continue;
    }
    $abs = $a['resume_path'] !== '' ? $root . '/' . ltrim($a['resume_path'], '/') : '';
    $text = $abs && is_file($abs) ? extractResumeText($abs) : '';
    if (trim($text) === '') {
        continue;
    }
    if (!resumeLooksRelated($text, $job)) {
        $mismatches[] = $id;
        $report['applicants'][$id]['mismatch'] = true;
    }
}
$report['mismatch_ids'] = $mismatches;

/* ------------------------------------------------------------------ */
/* Placeholder job requirements                                        */
/* ------------------------------------------------------------------ */
$jobIssues = [];
foreach ($jobs as $j) {
    $issues = [];
    foreach ([
        ['required_skills', 'required_skills'],
        ['qualifications', 'qualifications'],
        ['education_requirement', 'education_requirement'],
        ['experience_requirement', 'experience_requirement'],
        ['description', 'description'],
    ] as [$col, $label]) {
        $st = requirementFieldStatus($j, $col, $label);
        if ($st['status'] !== 'ok') {
            $issues[] = $st;
        }
    }
    if ($issues) {
        $jobIssues[] = [
            'job_id'    => (int) $j['id'],
            'title'     => $j['title'],
            'issues'    => $issues,
        ];
    }
}
$report['job_requirement_issues'] = $jobIssues;

/* ------------------------------------------------------------------ */
/* Output                                                              */
/* ------------------------------------------------------------------ */
if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(0);
}

echo "=== HR1 DATA QUALITY REPORT ({$today}) ===\n";
echo "Total applicants: {$report['total_applicants']}\n\n";

echo "--- Unique resume content sets (by SHA-256) ---\n";
foreach ($report['resume_sets'] as $set) {
    printf(
        "  %-3d applicant(s) | %s\n        ids: %s\n",
        $set['count'],
        $set['sample'],
        implode(', ', $set['applicant_ids'])
    );
}

echo "\n--- Resume / position MISMATCHES ---\n";
if (!$mismatches) {
    echo "  (none detected)\n";
} else {
    foreach ($mismatches as $id) {
        $a = $report['applicants'][$id];
        printf(
            "  #%d %-18s pos='%s' jobId=%s resume='%s'\n",
            $id, $a['name'], $a['position'], var_export($a['job_id'], true), $a['resume_path']
        );
    }
}

echo "\n--- Resume state problems (missing / invalid) ---\n";
$stateProblems = array_filter($report['applicants'], fn($a) => $a['resume_state'] !== 'ok');
if (!$stateProblems) {
    echo "  (none)\n";
} else {
    foreach ($stateProblems as $a) {
        printf("  #%d %-18s state=%s resume='%s'\n", $a['id'], $a['name'], $a['resume_state'], $a['resume_path']);
    }
}

echo "\n--- Job requirement issues (empty / placeholder) ---\n";
if (!$jobIssues) {
    echo "  (none)\n";
} else {
    foreach ($jobIssues as $j) {
        echo "  job #{$j['job_id']} {$j['title']}\n";
        foreach ($j['issues'] as $iss) {
            printf("      %-24s %-11s %s\n", $iss['field'], $iss['status'], mb_substr($iss['value'], 0, 60));
        }
    }
}

echo "\n=== END ===\n";
exit(0);

/**
 * Small helper to re-extract text for a single applicant's resume (used only
 * for building snippet strings). Kept local to avoid loading shared state.
 */
function extractResumeTextFn(string $root, string $resumePath): string
{
    $abs = $root . '/' . ltrim($resumePath, '/');
    return is_file($abs) ? extractResumeText($abs) : '';
}
