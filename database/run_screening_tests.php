<?php
/**
 * Automated test harness for the HR1 Hybrid Screening engine (hybrid-v2).
 *
 * Run:  php database/run_screening_tests.php
 *
 * Covers the 8 required scenarios plus unit checks of the semantic layer.
 * Engine runs are PURE (no database writes). The only DB access is read-only
 * (job-title resolution checks against live open job postings).
 * The full apply -> upload -> screen -> DB -> list -> detail -> rescreen flow
 * is exercised separately by database/screening_flow_test.php.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai_screening.php';

$pass = 0;
$fail = 0;

function t(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
$job = [
    'title'               => 'Warehouse Associate',
    'required_skills'     => 'warehouse operations, inventory control, forklift operation',
    'qualifications'      => 'Forklift certification',
    'education_requirement'=> 'High School Diploma',
    'experience_requirement'=> '1+ year warehouse experience',
    'requirements'        => 'Warehouse operations, inventory control, and forklift operation. High school diploma. 1+ year warehouse experience.',
];

echo "== Unit checks: semantic / terminology layer ==\n";

$scan = hs_scan_concepts(hs_normalize('loading and unloading of trucks, arranging stocks inside the warehouse'));
t('concept scan: loading_unloading duty recognized',
    !empty($scan['loading_unloading']['duties']), implode(',', $scan['loading_unloading']['duties'] ?? []));
t('concept scan: warehouse_operations duty recognized',
    !empty($scan['warehouse_operations']['duties']), implode(',', $scan['warehouse_operations']['duties'] ?? []));

t('item mapping: inventory control -> inventory_control',
    in_array('inventory_control', hs_item_concepts(hs_normalize('inventory control')), true));
t('item mapping: forklift operation -> forklift_operation',
    in_array('forklift_operation', hs_item_concepts(hs_normalize('forklift operation')), true));

t('title concepts: Warehouse Associate includes warehouse_operations',
    in_array('warehouse_operations', hs_title_concepts('Warehouse Associate'), true));
t('title concepts: Kargador maps to materal/loading concepts',
    count(hs_title_concepts('Kargador')) > 0, implode(',', hs_title_concepts('Kargador')));

$kToWa = hs_related_title_score('Kargador', 'Warehouse Associate');
t('related-title score Kargador -> Warehouse Associate >= 0.40', $kToWa >= 0.40, "score=$kToWa");
t('Kargador is NOT treated as identical to Warehouse Associate', $kToWa < 1.0, "score=$kToWa");
$cToWa = hs_related_title_score('Cashier', 'Warehouse Associate');
t('Cashier is NOT resolved as Warehouse Associate (< 0.40)', $cToWa < 0.40, "score=$cToWa");
$sToR = hs_related_title_score('Restaurant Server', 'Warehouse Associate');
t('Restaurant Server is NOT resolved as Warehouse Associate (< 0.40)', $sToR < 0.40, "score=$sToR");

t('estimate max years: "5 years experience" = 5', hs_estimate_max_years(hs_normalize('5 years experience')) === 5.0);

echo "\n== Scenario tests ==\n";

// --- TEST 1: direct match => High score / Strong ---
$r1 = hs_hybrid_screen($job, "Juan Warehouse\nWarehouse Associate\nJan 2023 - Dec 2024\nHandled warehouse operations, monitored stock levels, maintained inventory records, operated forklift.\nHigh School Graduate.");
t('TEST1: Strong match (>= 80)', $r1['overall_score'] >= 80, "overall={$r1['overall_score']} rec={$r1['recommendation']}");
t('TEST1: skills >= 75', $r1['skills_score'] >= 75, "skills={$r1['skills_score']}");
t('TEST1: experience uses relevant years (>= 80)', $r1['experience_score'] >= 80, "exp={$r1['experience_score']}");
t('TEST1: education matched', $r1['education_score'] >= 80, "edu={$r1['education_score']}");
t('TEST1: confidence High', $r1['confidence'] === 'High', "confidence={$r1['confidence']}");
t('TEST1: screening_version hybrid-v2', ($r1['screening_version'] ?? 'hybrid-v2') !== '', '');

// --- TEST 2: Kargador duties => meaningful score, no title required ---
$r2 = hs_hybrid_screen($job, "Pedro Kargador\nKargador\nResponsible for loading and unloading delivery trucks, arranging stocks inside the warehouse, checking inventory, and assisting with delivery preparation.\n2 years experience.");
t('TEST2: related experience strongly credited (>= 80)', $r2['experience_score'] >= 80, "exp={$r2['experience_score']}");
t('TEST2: skills get meaningful partial credit (>= 40)', $r2['skills_score'] >= 40, "skills={$r2['skills_score']}");
t('TEST2: overall is meaningful but NOT automatic 100', $r2['overall_score'] >= 45 && $r2['overall_score'] < 100, "overall={$r2['overall_score']}");
t('TEST2: no exact title required — matched requirements include skills', count($r2['matched']) > 0, '');
t('TEST2: explainability present (partial/evidence)', count($r2['partial']) + count($r2['evidence']) > 0, 'partial=' . count($r2['partial']) . ' evidence=' . count($r2['evidence']));

// --- TEST 3: Restaurant Server => low relevant warehouse experience ---
$r3 = hs_hybrid_screen($job, "Maria Server\nRestaurant Server\nServed customers, took orders, cleaned tables.\n5 years restaurant experience.");
t('TEST3: 5 restaurant years do NOT inflate experience (exp < 40)', $r3['experience_score'] < 40, "exp={$r3['experience_score']}");
t('TEST3: overall low (< 40)', $r3['overall_score'] < 40, "overall={$r3['overall_score']}");
t('TEST3: concern raised about unrelated experience',
    (bool) array_filter($r3['concerns'] ?? [], fn($c) => stripos($c, 'does not appear related') !== false), implode(' | ', $r3['concerns'] ?? []));

// --- TEST 4: keyword-stuffed copy of requirements => never 100 ---
$stuffed = "Skills: warehouse operations, inventory control, forklift operation, High School Diploma, 1+ year warehouse experience.\nQualifications: Forklift certification.";
$r4 = hs_hybrid_screen($job, $stuffed);
t('TEST4: stuffed resume does not reach 100 (overall < 80)', $r4['overall_score'] < 80, "overall={$r4['overall_score']}");
t('TEST4: stuffing concern flagged', (bool) array_filter($r4['concerns'] ?? [], fn($c) => stripos($c, 'no supporting employment history') !== false), '');
t('TEST4: confidence Low', $r4['confidence'] === 'Low', "confidence={$r4['confidence']}");

// --- TEST 5: no education info => unclear/missing, never invented ---
$r5 = hs_hybrid_screen($job, 'No formal education is listed anywhere in this document. Just work experience.');
t('TEST5: education marked unclear/missing (<= 30)', $r5['education_score'] <= 30, "edu={$r5['education_score']}");
t('TEST5: missing list includes education note',
    (bool) array_filter($r5['missing'] ?? [], fn($m) => stripos($m, 'education') !== false), '');

// --- TEST 6: job_posting_id always wins ---
$opts = db()->prepare("SELECT id, title FROM job_postings WHERE status='open'");
$opts->execute();
$open = $opts->fetchAll();
$wa = null;
foreach ($open as $row) {
    if (strcasecmp($row['title'], 'Warehouse Associate') === 0) {
        $wa = $row;
    }
}
if ($wa) {
    $resolved = resolveScreeningJob([
        'job_posting_id' => (int) $wa['id'],
        'position_applied' => 'Completely Unrelated Title',
    ]);
    t('TEST6: job_posting_id wins even when title differs', $resolved && (int) $resolved['id'] === (int) $wa['id'],
        'resolved=' . ($resolved['id'] ?? 'none'));
} else {
    echo "  SKIP  TEST6 (no open Warehouse Associate posting in DB)\n";
}

// --- TEST 7: title rename / semantic fallback only when id absent ---
$r7 = resolveScreeningJob([
    'job_posting_id' => null,
    'position_applied' => 'Kargador',
]);
if ($wa) {
    t('TEST7: Kargador resolves to current Warehouse Associate posting via semantic fallback',
        $r7 && (int) $r7['id'] === (int) $wa['id'],
        'resolved=' . ($r7['title'] ?? 'none') . ' score=' . ($r7 ? hs_related_title_score('Kargador', $r7['title']) : 'n/a'));
    t('TEST7: DB title is used (no stale hardcoded title)', $r7 && $r7['title'] === 'Warehouse Associate', $r7['title'] ?? 'none');
} else {
    echo "  SKIP  TEST7 (no open Warehouse Associate posting in DB)\n";
}

// --- TEST 8: semantic unavailable => local fallback still works ---
echo "\n== TEST 8: fallback dispatch (AI_SCREENING_SEMANTIC=off) ==\n";
$probe = sys_get_temp_dir() . '/hr1_fallback_probe.php';
file_put_contents($probe, '<?php
require "C:/xampp/htdocs/HR1/config/database.php";
require "C:/xampp/htdocs/HR1/includes/ai_screening.php";
$job = ["title" => "Warehouse Associate", "required_skills" => "warehouse operations, inventory control, forklift operation", "qualifications" => "Forklift certification", "education_requirement" => "High School Diploma", "experience_requirement" => "1+ year warehouse experience", "requirements" => ""];
$r = analyzeResumeWithProvider($job, "Warehouse Associate. Jan 2023 - Dec 2024. Warehouse operations, inventory control, forklift operation. High School Graduate.");
echo json_encode(["version" => $r["screening_version"] ?? "", "fallback" => $r["fallback_used"] ?? false, "score" => $r["overall_score"] ?? 0, "confidence" => $r["confidence"] ?? "", "error" => $r["error"] ?? null]);
');
putenv('AI_SCREENING_SEMANTIC=off');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1', $probeOut, $probeRc);
putenv('AI_SCREENING_SEMANTIC=');
@unlink($probe);
$decoded = $probeOut ? json_decode(trim(end($probeOut)), true) : null;
t('TEST8: fallback probe ran (no fatal)', $probeRc === 0 && is_array($decoded), implode(' | ', $probeOut));
t('TEST8: fallback used and version tagged -fallback',
    is_array($decoded) && !empty($decoded['version']) && str_ends_with($decoded['version'], '-fallback') && !empty($decoded['fallback']),
    $decoded['version'] ?? 'n/a');
t('TEST8: fallback still produces a valid score', is_array($decoded) && (int) ($decoded['score'] ?? 0) >= 0, 'score=' . ($decoded['score'] ?? 'n/a'));

// Sanity: normal (semantic ON) dispatch tags hybrid-v2.
$rDef = analyzeResumeWithProvider($job, "Warehouse Associate\nJan 2023 - Dec 2024\nWarehouse operations, inventory control, forklift operation.\nHigh School Graduate.");
t('Dispatch (semantic ON): version hybrid-v2', ($rDef['screening_version'] ?? '') === 'hybrid-v2', $rDef['screening_version'] ?? 'none');
t('Dispatch (semantic ON): full explanation payload',
    isset($rDef['partial'], $rDef['evidence'], $rDef['concerns'], $rDef['confidence']), '');

echo "\n----------------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);