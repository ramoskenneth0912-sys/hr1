<?php
/**
 * HR1 Hybrid Screening Engine ("hybrid-v2")
 *
 * A semantic + rule-based scoring layer on top of the existing resume parser
 * and ai_screening infrastructure. It is deterministic, on-premise, and needs
 * no API key. It implements:
 *
 *   1. Requirement concept parsing   — each requirement is broken into
 *      structured occupational concepts (see screening_terms.php).
 *   2. Resume understanding          — role titles, duties, concept evidence,
 *      education markers, industry hints and dated experience segments are
 *      extracted from the resume text.
 *   3. Semantic/contextual matching  — related terminology ("arranging stocks",
 *      "loading and unloading") is recognized as EVIDENCE, never as an
 *      automatic synonym. Job titles are only used as supporting evidence.
 *   4. Rule-based verification       — evidence strength tiers (exact / named /
 *      strong / partial / weak / none) plus contextual guards.
 *   5. Relevant-experience scoring   — only experience that demonstrates the
 *      required concepts counts toward the required years; unrelated years
 *      ("Restaurant Server — 5 years") do not inflate the score.
 *   6. Explainability               — every result includes partial matches,
 *      evidence strings, concerns, and a High/Medium/Low confidence value.
 *   7. Anti keyword-stuffing        — a resume that merely copies the job
 *      requirements without supported employment history cannot reach 100%.
 *
 * This file is self-contained: the existing localScreeningAnalysis() in
 * ai_screening.php is untouched and remains the fallback whenever the
 * semantic layer is disabled (AI_SCREENING_SEMANTIC=off).
 */

/**
 * Concept/title index cache.
 */
function hs_concept_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $file = __DIR__ . '/screening_terms.php';
        $cache = is_file($file) ? (require $file) : [];
    }
    return $cache;
}

/**
 * Flattened title index: normalized job title => list of concept keys.
 * A single title may belong to several related concepts.
 */
function hs_title_index(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    $index = [];
    foreach (hs_concept_cache() as $key => $def) {
        foreach (($def['titles'] ?? []) as $t) {
            $norm = hs_normalize((string) $t);
            $index[$norm] = $index[$norm] ?? [];
            if ($norm !== '' && !in_array($key, $index[$norm], true)) {
                $index[$norm][] = $key;
            }
        }
    }
    return $index;
}

/**
 * Low-level normalized form: lowercase, alphanumeric-only, single spaces.
 */
function hs_normalize(string $text): string
{
    $text = mb_strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
    return trim((string) preg_replace('/\s+/', ' ', $text));
}

/**
 * Token list of a normalized string.
 */
function hs_tokens(string $norm): array
{
    if (trim($norm) === '') {
        return [];
    }
    return preg_split('/\s+/', $norm) ?: [];
}

/**
 * Lightweight 6-character root (keeps plural/tense variants together).
 */
function hs_root(string $word): string
{
    $word = mb_strtolower(trim($word));
    if (mb_strlen($word) > 6) {
        $word = mb_substr($word, 0, 6);
    }
    return $word;
}

/**
 * True when an entire normalized phrase is present, boundary-aware.
 */
function hs_contains(string $normHaystack, string $phraseNorm): bool
{
    $phraseNorm = trim((string) preg_replace('/\s+/', ' ', $phraseNorm));
    if ($phraseNorm === '') {
        return false;
    }
    if (preg_match('/^[a-z0-9]{1,2}$/', $phraseNorm)) {
        return false; // too short / ambiguous
    }
    $quoted = preg_quote($phraseNorm, '/');
    return (bool) preg_match('/(^|[^a-z0-9])' . $quoted . '($|[^a-z0-9])/i', $normHaystack);
}

/**
 * Scan text and build per-concept evidence.
 *
 * @return array<string, array{label:string, terms:list<string>, duties:list<string>, cores:list<string>, present:bool, duty:bool, term:bool}>
 */
function hs_scan_concepts(string $normText, ?array $concepts = null): array
{
    $concepts = $concepts ?? hs_concept_cache();
    $hits = [];
    foreach ($concepts as $key => $def) {
        $entry = [
            'label'   => $def['label'],
            'terms'   => [],
            'duties'  => [],
            'cores'   => [],
            'present' => false,
            'duty'    => false,
            'term'    => false,
        ];
        foreach (($def['terms'] ?? []) as $t) {
            $tn = hs_normalize($t);
            if ($tn !== '' && hs_contains($normText, $tn)) {
                $entry['terms'][] = $t;
                $entry['term'] = true;
                $entry['present'] = true;
            }
        }
        foreach (($def['duties'] ?? []) as $d) {
            $dn = hs_normalize($d);
            if ($dn !== '' && hs_contains($normText, $dn)) {
                $entry['duties'][] = $d;
                $entry['duty'] = true;
                $entry['present'] = true;
            }
        }
        foreach (($def['cores'] ?? []) as $c) {
            $cn = hs_normalize($c);
            $root = hs_root($cn);
            if ($cn !== '' && !in_array($cn, array_map('hs_normalize', array_merge($entry['terms'], $entry['duties'])), true)) {
                if (hs_contains($normText, $cn) || (mb_strlen($cn) >= 3 && in_array($root, array_map('hs_root', hs_tokens($normText)), true))) {
                    $entry['cores'][] = $c;
                }
            }
        }
        $hits[$key] = $entry;
    }
    return $hits;
}

/**
 * How much concept-level evidence exists in $normText (for confidence).
 */
function hs_concept_evidence_strength(array $hits): int
{
    $score = 0;
    foreach ($hits as $h) {
        if ($h['duty']) {
            $score += 2;
        } elseif ($h['term']) {
            $score += 1;
        }
    }
    return $score;
}

/**
 * Split a free-text requirement string into meaningful items.
 * Reuses the legacy extractItems() helper when available, otherwise falls
 * back to comma / semicolon / line splitting.
 */
function hs_extract_items(string $text): array
{
    if (function_exists('extractItems')) {
        return extractItems($text);
    }
    $parts = preg_split('/[,;\n|\/]+/', $text);
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '' && mb_strlen($p) >= 3) {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * Map a requirement item to the concepts that its own wording names.
 *
 * @return list<string> concept keys
 */
function hs_item_concepts(string $itemNorm, ?array $concepts = null): array
{
    $concepts = $concepts ?? hs_concept_cache();
    $keys = [];
    foreach ($concepts as $key => $def) {
        $named = false;
        foreach (array_merge($def['terms'] ?? [], $def['cores'] ?? []) as $t) {
            if (hs_contains($itemNorm, hs_normalize((string) $t))) {
                $named = true;
                break;
            }
        }
        if (!$named) {
            foreach (hs_tokens($itemNorm) as $tok) {
                if (mb_strlen($tok) >= 3 && in_array(hs_root($tok), array_map('hs_root', hs_tokens(hs_normalize(implode(' ', $def['terms'])))), true)) {
                    $named = true;
                    break;
                }
            }
        }
        if ($named) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/**
 * True when the resume shows real experiential context: a year, a strong role
 * marker ("worked as", "employed as", ...), or at least one literal duty verb
 * phrase ("loading and unloading", "arranging stocks", "monitored stock
 * levels"). A bare keyword paste of the job requirements ("1+ year warehouse
 * experience") matches none of these and therefore does NOT qualify.
 */
function hs_experiential_context(string $normText): bool
{
    if ((bool) preg_match('/\b(19|20)\d{2}\b/', $normText)) {
        return true;
    }
    if ((bool) preg_match('/\b(worked as|working as|was a|as a |as an |employed as|position of|held the position)\b/', $normText)) {
        return true;
    }
    foreach (hs_concept_cache() as $def) {
        foreach (($def['duties'] ?? []) as $d) {
            if (hs_contains($normText, hs_normalize((string) $d))) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Extract dated/tenured experience segments from resume text.
 *
 * @return array{years:float, text:string, weak:bool, via:string}
 */
function hs_experience_segments(string $normText, string $rawText): array
{
    $lines = preg_split('/\R/', trim($rawText));
    $segments = [];
    $prev = [];
    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount; $i++) {
        $line = trim((string) $lines[$i]);
        if ($line === '') {
            continue;
        }
        $tenure = hs_line_tenure($line);
        if ($tenure !== null) {
            $buf = array_merge(array_slice($prev, -3), [$line]);
            if ($i + 1 < $lineCount) {
                $nextLine = trim((string) $lines[$i + 1]);
                if ($nextLine !== '') {
                    $buf[] = $nextLine;
                }
            }
            $segText = hs_normalize(implode(' ', $buf));

            // A segment is trusted when the tenure line itself has a role
            // marker OR the surrounding segment text names a known job title
            // ("Warehouse Associate" ... "Jan 2023 - Dec 2024").
            $strong = $tenure['role'];
            if (!$strong) {
                foreach (array_keys(hs_title_index()) as $titleNorm) {
                    if (hs_contains($segText, $titleNorm)) {
                        $strong = true;
                        break;
                    }
                }
            }

            $segments[] = [
                'years' => $tenure['years'],
                'text'  => $segText,
                'weak'  => !$strong,
                'via'   => $tenure['via'],
            ];
        }
        $prev[] = $line;
        if (count($prev) > 4) {
            array_shift($prev);
        }
    }

    if (!$segments) {
        // Fallback: treat the whole resume as a single (weak) segment.
        $yrs = hs_estimate_max_years($normText);
        if ($yrs > 0) {
            $segments[] = [
                'years' => $yrs,
                'text'  => $normText,
                'weak'  => true,
                'via'   => 'estimate',
            ];
        }
    }
    return $segments;
}

/**
 * Detect tenure on a single resume line.
 *
 * Returns null when no tenure, or ['years'=>float, 'role'=>bool, 'via'=>string]
 * where 'role' means the line (plus nothing) is tied to a job title or
 * role marker — a raw requirement paste like "1+ year warehouse experience"
 * is deliberately NOT treated as a role.
 */
function hs_line_tenure(string $line): ?array
{
    $norm = hs_normalize($line);
    if ($norm === '') {
        return null;
    }

    // Job-title / role marker present on this line?
    $role = false;
    foreach (array_keys(hs_title_index()) as $titleNorm) {
        if (hs_contains($norm, $titleNorm)) {
            $role = true;
            break;
        }
    }
    if (!$role && (bool) preg_match('/\b(worked as|working as|was a|as a \w+|as an \w+|employed as|position of|held the position)\b/', $norm)) {
        $role = true;
    }

    // Date span: "Jan 2022 – June 2024", "2022 - 2024", "2022 – Present".
    $yearsAt = [];
    if (preg_match_all('/\b(19|20)\d{2}\b/', $norm, $m)) {
        $yearsAt = array_map('intval', $m[0]);
    }
    if (count($yearsAt) >= 2) {
        $start = min($yearsAt);
        $end = max($yearsAt);
        return ['years' => max(0.1, (float) ($end - $start)), 'role' => $role, 'via' => 'dates'];
    }
    if (count($yearsAt) === 1) {
        if ((bool) preg_match('/present|current|until now|to date/i', $norm) && $yearsAt[0] > 0) {
            $span = (float) ((int) date('Y') - $yearsAt[0]);
            return ['years' => max(0.1, $span), 'role' => $role, 'via' => 'dates'];
        }
    }

    // Numeric tenure: "5 years", "1+ year", "2 yrs", "6 months".
    if (!$role && !preg_match('/\b(19|20)\d{2}\b/', $norm)) {
        return null; // enforce role marker for numbers, prevents requirement pastes
    }
    if (preg_match('/(\d+(?:\.\d+)?)\+?\s*(?:years?|yrs?)\b/i', $norm, $m)) {
        return ['years' => max(0.1, (float) $m[1]), 'role' => $role, 'via' => 'number'];
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*months?\b/i', $norm, $m) !== 1) {
        return null;
    }
    return ['years' => max(0.1, (float) $m[1] / 12.0), 'role' => $role, 'via' => 'months'];
}

/**
 * Rough "maximum years found anywhere" estimate (used only when no dated
 * segments exist). Mirrors the spirit of the legacy estimateResumeYears().
 */
function hs_estimate_max_years(string $normText): float
{
    $max = 0.0;
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:years?|yrs?)\b/i', $normText, $m)) {
        foreach ($m[1] as $y) {
            $max = max($max, (float) $y);
        }
    }
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*months?\b/i', $normText, $mm)) {
        foreach ($mm[1] as $mo) {
            $max = max($max, (float) $mo / 12.0);
        }
    }
    $yearsAt = [];
    if (preg_match_all('/\b(19|20)\d{2}\b/', $normText, $y)) {
        $yearsAt = array_map('intval', $y[0]);
    }
    if (count($yearsAt) >= 2) {
        $span = max($yearsAt) - min($yearsAt);
        $max = max($max, (float) $span);
    }
    return round($max, 1);
}

/**
 * Parse the number of years demanded by an experience requirement.
 * Returns null when no exact number is stated.
 */
function hs_required_years(string $experienceReq): ?float
{
    $norm = hs_normalize($experienceReq);
    if ($norm === '' || preg_match('/\bno prior|\bno (work |working )?experience|\bfresh graduate|\bentry level/i', $norm)) {
        return 0.0;
    }
    if (preg_match('/(\d+(?:\.\d+)?)\+?\s*(?:years?|yrs?)\b/i', $norm, $m)) {
        return (float) $m[1];
    }
    foreach (['one', 'two', 'three', 'four', 'five', 'six'] as $i => $w) {
        if (preg_match('/\b' . $w . '\s+(?:\(?\d?\)?\s*)?(?:years?|yrs?)\b/i', $norm)) {
            return (float) ($i + 1);
        }
    }
    return null;
}

/**
 * Relevance of one experience segment (0..100) to the required concepts.
 *
 * Per required concept credit: duty phrase = 2, matching role title = 2,
 * direct term = 1, nothing = 0. The score is capped at 100.
 */
function hs_segment_relevance(string $segmentNorm, array $requiredConcepts, array $concepts): int
{
    $hits = hs_scan_concepts($segmentNorm, $concepts);

    if (empty($requiredConcepts)) {
        $dutyCount = $termCount = 0;
        foreach ($hits as $h) {
            $dutyCount += $h['duty'] ? 1 : 0;
            $termCount += $h['term'] ? 1 : 0;
        }
        return min(100, 20 + ($dutyCount > 0 ? 50 : 0) + $termCount * 10);
    }

    $requiredConceptKeys = array_fill_keys($requiredConcepts, null);
    $credits = 0;
    foreach ($requiredConcepts as $key) {
        $h = $hits[$key] ?? null;

        $titleMatch = false;
        foreach (hs_title_index() as $titleNorm => $titleKeys) {
            if (hs_contains($segmentNorm, $titleNorm) && in_array($key, $titleKeys, true)) {
                $titleMatch = true;
                break;
            }
        }

        if ($h && $h['duty']) {
            $credits += 2;
        } elseif ($titleMatch) {
            $credits += 2;
        } elseif ($h && $h['term']) {
            $credits += 1;
        }
    }

    $maxCredits = max(1, count($requiredConceptKeys) * 2);
    return min(100, (int) round($credits / $maxCredits * 100));
}

/**
 * Experience scoring with relevant-vs-unrelated separation.
 *
 * @return array{score:int, relevant_years:float, total_years:float, context_fraction:float, detail:string}
 */
function hs_score_experience(string $requiredExperience, string $normText, string $rawText, array $requiredConcepts): array
{
    $concepts = hs_concept_cache();

    if (trim($requiredExperience) === '') {
        return [
            'score'           => 70,
            'relevant_years'  => 0.0,
            'total_years'     => 0.0,
            'context_fraction'=> 0.0,
            'detail'          => 'No specific experience requirement defined for this position',
        ];
    }

    $segments = hs_experience_segments($normText, $rawText);

    $relevantYears = 0.0;
    $totalYears = 0.0;
    foreach ($segments as $seg) {
        if ($seg['years'] <= 0) {
            continue;
        }
        $totalYears += $seg['years'];
        $rel = hs_segment_relevance($seg['text'], $requiredConcepts, $concepts) / 100.0;
        // Weak segments (tenure not tied to a role/title) are only half-trusted.
        if ($seg['weak']) {
            $rel *= 0.5;
        }
        $relevantYears += $seg['years'] * $rel;
    }

    // Context fraction = average concept evidence across all segment text
    // (or the whole resume when no segments were found).
    $contextText = $normText;
    if ($segments) {
        $contextText = hs_normalize(implode(' ', array_column($segments, 'text')));
    }
    $contributionSum = 0.0;
    $contributionCount = 0;
    if (!empty($requiredConcepts)) {
        $ctxHits = hs_scan_concepts($contextText, $concepts);
        foreach ($requiredConcepts as $key) {
            $h = $ctxHits[$key] ?? null;
            if ($h && $h['present']) {
                $contributionSum += $h['duty'] ? 1.0 : 0.65;
            }
            $contributionCount++;
        }
    }
    $contextFraction = $contributionCount > 0 ? round($contributionSum / $contributionCount * 100) : 0;
    $relevantYears = round($relevantYears, 1);
    $totalYears = round($totalYears, 1);

    $reqYears = hs_required_years($requiredExperience);

    if ($reqYears > 0) {
        if ($totalYears <= 0 && $relevantYears <= 0) {
            $yearScore = $contextFraction > 0 ? 35 : 10;
        } elseif ($relevantYears >= $reqYears) {
            $yearScore = 100;
        } elseif ($relevantYears > 0) {
            $yearScore = max(25, min(90, (int) round($relevantYears / $reqYears * 100)));
        } else {
            $yearScore = 25; // has years, but none relevant to the required work
        }
    } else {
        $yearScore = $totalYears > 0 || $relevantYears > 0 ? 80 : 50;
    }

    $score = $reqYears === null && $contextFraction === 0 && $relevantYears <= 0
        ? 50
        : (int) round($yearScore * 0.7 + $contextFraction * 0.3);
    $score = min(100, max(0, $score));

    $detail = sprintf(
        'Relevant experience: %.1f yr (total %.1f yr on resume) — %.0f%% of required duty context evidenced',
        $relevantYears,
        $totalYears,
        $contextFraction
    );

    return [
        'score'            => $score,
        'relevant_years'   => $relevantYears,
        'total_years'      => $totalYears,
        'context_fraction' => $contextFraction,
        'detail'           => $detail,
    ];
}

/**
 * Evidence strength classification for a single requirement item.
 *
 * @return array{score:int, tier:string, evidence:list<string>, clear:bool}
 */
function hs_item_evidence(string $item, string $normText, array $concepts, bool $experiential): array
{
    $itemNorm = hs_normalize($item);
    if ($itemNorm === '') {
        return ['score' => 0, 'tier' => 'none', 'evidence' => [], 'clear' => false];
    }

    $itemConceptKeys = hs_item_concepts($itemNorm, $concepts);
    $evidence = [];

    if ($experiential && hs_contains($normText, $itemNorm)) {
        // Category-exact literal match WITH experiential context.
        return ['score' => 100, 'tier' => 'exact', 'evidence' => ['exact phrase matched'], 'clear' => true];
    }

    // Look for concept-level evidence in the resume.
    $hits = hs_scan_concepts($normText, $concepts);
    $matchedKeys = [];
    foreach ($itemConceptKeys as $key) {
        $h = $hits[$key] ?? null;
        if (!$h || !$h['present']) {
            continue;
        }
        $matchedKeys[] = $key;
        if ($h['duty'] && $experiential) {
            $evidence[] = 'duty: ' . implode(', ', $h['duties']);
        } elseif ($h['duty']) {
            $evidence[] = 'duty: ' . implode(', ', $h['duties']);
        } elseif ($h['term']) {
            $evidence[] = 'related term: ' . implode(', ', $h['terms']);
        }
    }

    if ($matchedKeys) {
        $hasDuty = false;
        foreach ($matchedKeys as $key) {
            if (($hits[$key]['duty'] ?? false) === true) {
                $hasDuty = true;
                break;
            }
        }
        // Duty verbs are the strongest behavioral evidence.
        if ($hasDuty && $experiential) {
            return ['score' => 90, 'tier' => 'strong', 'evidence' => $evidence, 'clear' => true];
        }
        if ($hasDuty) {
            return ['score' => 75, 'tier' => 'strong', 'evidence' => $evidence, 'clear' => true];
        }
        return ['score' => 60, 'tier' => 'named', 'evidence' => $evidence, 'clear' => true];
    }

    // Literal phrase present but no experiential context = possible stuffing.
    if (hs_contains($normText, $itemNorm)) {
        return ['score' => 60, 'tier' => 'named', 'evidence' => ['keyword present, but no supporting employment context found'], 'clear' => false];
    }

    // Weak single-root overlap.
    $itemTokens = array_map('hs_root', hs_tokens($itemNorm));
    $resumeRoots = array_map('hs_root', hs_tokens($normText));
    $shared = array_values(array_unique(array_intersect($itemTokens, $resumeRoots)));
    $shared = array_filter($shared, fn($r) => mb_strlen($r) >= 4);
    if ($shared) {
        return ['score' => 25, 'tier' => 'weak', 'evidence' => ['partial root overlap: ' . implode(', ', $shared)], 'clear' => true];
    }

    return ['score' => 0, 'tier' => 'none', 'evidence' => [], 'clear' => true];
}

/**
 * Score a requirement category (skills or qualifications).
 *
 * @return array{score:int, matched:list<string>, partial:list<string>, missing:list<string>, evidence:list<string>, unclear:int}
 */
function hs_score_category(string $requirements, string $normText, array $concepts, string $categoryLabel, bool $experiential): array
{
    $items = hs_extract_items($requirements);
    if (empty($items)) {
        return ['score' => 0, 'matched' => [], 'partial' => [], 'missing' => [], 'evidence' => [], 'unclear' => 0];
    }

    $scores = [];
    $matched = [];
    $partial = [];
    $missing = [];
    $evidence = [];
    $unclear = 0;

    foreach ($items as $item) {
        $ev = hs_item_evidence($item, $normText, $concepts, $experiential);
        $label = $categoryLabel . ': ' . trim($item);
        $scores[] = $ev['score'];
        foreach ($ev['evidence'] as $eDoc) {
            $evidence[] = $label . ' — ' . $eDoc;
        }
        if (!$ev['clear']) {
            $unclear++;
        }
        if ($ev['score'] >= 60) {
            $matched[] = $label . ($ev['score'] < 100 ? ' (related wording)' : '');
        } elseif ($ev['score'] >= 25) {
            $partial[] = $label . ' — ' . ($ev['evidence'] ? implode('; ', $ev['evidence']) : 'partial match');
            $matched[] = $label . ' (partial)';
        } else {
            $missing[] = $label . ($ev['tier'] === 'none' ? ' — no evidence found' : ' — ' . implode('; ', $ev['evidence']));
        }
    }

    $score = $scores ? (int) round(array_sum($scores) / count($scores)) : 0;
    return [
        'score'    => min(100, max(0, $score)),
        'matched'  => $matched,
        'partial'  => $partial,
        'missing'  => $missing,
        'evidence' => $evidence,
        'unclear'  => $unclear,
    ];
}

/**
 * Education matching. Reuses the legacy scoreEducationMatch() when available
 * (it already handles degree levels/fields and related-field phrasings) so the
 * classification stays consistent; a small built-in fallback keeps this file
 * standalone.
 *
 * @return array{score:int, matched:list<string>, missing:list<string>, exists:bool, found:bool, detail:string}
 */
function hs_score_education(string $educationReq, string $normText, string $metaEducation): array
{
    $haystack = $normText;
    if (trim($metaEducation) !== '') {
        $haystack .= ' ' . hs_normalize('education: ' . $metaEducation);
    }

    $foundMarkers = (bool) preg_match(
        '/\b(high school|secondary|college|university|degree|diploma|graduate|graduate school|vocational|certificate|master|\bbb|bs |ba|\bnd|\bnc|vocational|undergraduate|tertiary)\b/i',
        $haystack
    );

    if (trim($educationReq) === '') {
        return ['score' => 70, 'matched' => [], 'missing' => [], 'exists' => false, 'found' => $foundMarkers, 'detail' => 'No specific education requirement defined for this position'];
    }

    if (function_exists('scoreEducationMatch')) {
        [$score, $lsMatched, $lsMissing, $exists] = scoreEducationMatch($educationReq, $haystack);
        if (!$foundMarkers) {
            $lsMissing[] = 'Education information not found in resume or application';
            $score = min($score, 25);
        }
        return ['score' => $score, 'matched' => $lsMatched, 'missing' => $lsMissing, 'exists' => $exists, 'found' => $foundMarkers, 'detail' => ''];
    }

    // Minimal standalone fallback.
    $items = hs_extract_items($educationReq);
    $best = 0;
    $matched = [];
    $missing = [];
    foreach ($items as $it) {
        $itN = hs_normalize($it);
        if (hs_contains($haystack, $itN)) {
            $best = max($best, 100);
            $matched[] = $it;
        } else {
            $best = max($best, 25);
            $missing[] = $it . ' — not clearly evidenced';
        }
    }
    if (!$foundMarkers) {
        $missing[] = 'Education information not found in resume or application';
        $best = min($best, 25);
    }
    return ['score' => $best, 'matched' => $matched, 'missing' => $missing, 'exists' => true, 'found' => $foundMarkers, 'detail' => ''];
}

/**
 * Confidence rubric: reflects how much reliable evidence was found — NOT the
 * candidate's quality. A candidate can score 75% with Low confidence.
 */
function hs_confidence(
    int $dutyEvidence,
    int $strongDateSegments,
    int $totalSegments,
    bool $educationFound,
    bool $experiential,
    int $unclearItems
): string {
    if (!$experiential) {
        return 'Low';
    }
    if ($strongDateSegments >= 1 && $dutyEvidence >= 2 && ($educationFound || $unclearItems === 0)) {
        return 'High';
    }
    if ($strongDateSegments >= 1 || $totalSegments >= 1 || $dutyEvidence >= 2 || $experiential) {
        return 'Medium';
    }
    return 'Low';
}

/**
 * Semantic title → concept mapping, with related-concept expansion.
 *
 * @return list<string> concept keys
 */
function hs_title_concepts(string $title): array
{
    $norm = hs_normalize($title);
    if ($norm === '') {
        return [];
    }
    $keys = [];
    foreach (hs_title_index() as $titleNorm => $titleKeys) {
        if (hs_contains($norm, $titleNorm)) {
            foreach ($titleKeys as $k) {
                if (!in_array($k, $keys, true)) {
                    $keys[] = $k;
                }
            }
        }
    }
    // Direct term scan as secondary signal.
    foreach (hs_scan_concepts($norm) as $key => $h) {
        if ($h['term'] && !in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/**
 * Expand a concept set with each concept's related concepts.
 */
function hs_expand_concepts(array $keys): array
{
    $concepts = hs_concept_cache();
    $out = [];
    foreach ($keys as $k) {
        $out[] = $k;
        foreach (($concepts[$k]['related'] ?? []) as $r) {
            $out[] = $r;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Deterministic 0..1 similarity between two titles: token overlap +
 * related-concept overlap. Used ONLY as a fallback when no exact/normalized
 * title match exists and no job_posting_id is available.
 */
function hs_related_title_score(string $candidate, string $jobTitle): float
{
    $a = hs_normalize($candidate);
    $b = hs_normalize($jobTitle);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }

    $ta = hs_tokens($a);
    $tb = hs_tokens($b);
    $inter = count(array_intersect($ta, $tb));
    $union = count(array_unique(array_merge($ta, $tb)));
    $jaccard = $union > 0 ? $inter / $union : 0.0;

    $ca = hs_expand_concepts(hs_title_concepts($candidate));
    $cb = hs_expand_concepts(hs_title_concepts($jobTitle));
    $overlap = count(array_intersect($ca, $cb));
    $conceptDice = ($ca || $cb) ? (2 * $overlap) / max(1, count(array_unique($ca)) + count(array_unique($cb))) : 0.0;

    return round(min(1.0, 0.5 * $jaccard + 0.5 * $conceptDice), 2);
}

/**
 * Recommendation classification (mirrors screeningClassification()).
 */
function hs_recommendation(int $score): string
{
    if ($score >= 80) {
        return 'Strong Match';
    }
    if ($score >= 60) {
        return 'Moderate Match';
    }
    return 'Low Match';
}

/**
 * Main hybrid screening computation. Pure — never writes to the database.
 *
 * @param array<string,mixed> $job
 * @param array<string,mixed> $meta applicant row fields (education, skills, work_experience, position_applied)
 * @return array shape compatible with localScreeningAnalysis() + extras
 */
function hs_hybrid_screen(array $job, string $resumeText, array $meta = []): array
{
    $concepts = hs_concept_cache();
    if (empty($concepts)) {
        return ['error' => 'Semantic terminology layer not available.'];
    }
    if (trim($resumeText) === '') {
        return ['error' => 'Unable to analyze this resume. Please upload a clear PDF, DOC, or DOCX file.'];
    }

    $normText = hs_normalize($resumeText);
    $rawText = $resumeText;

    // Applicant-declared fields are secondary evidence sources.
    $metaSkills = trim((string) ($meta['skills'] ?? ''));
    $metaEducation = trim((string) ($meta['education'] ?? ''));
    $metaExp = trim((string) ($meta['work_experience'] ?? ''));
    $declared = trim($metaSkills . ' ' . $metaExp);
    $normDeclared = $declared !== '' ? hs_normalize($declared) : '';
    $normCombined = $normText . ($normDeclared !== '' ? ' ' . $normDeclared : '');

    $experiential = hs_experiential_context($normText);
    $hits = hs_scan_concepts($normCombined, $concepts);
    $dutyEvidence = 0;
    foreach ($hits as $h) {
        if ($h['duty']) {
            $dutyEvidence++;
        }
    }

    // === SKILLS ===
    $skills = hs_score_category((string) ($job['required_skills'] ?? ''), $normCombined, $concepts, 'Skill', $experiential);

    // === QUALIFICATIONS ===
    $quals = hs_score_category((string) ($job['qualifications'] ?? ''), $normCombined, $concepts, 'Qualification', $experiential);

    // === EDUCATION ===
    $edu = hs_score_education((string) ($job['education_requirement'] ?? ''), $normText, $metaEducation);

    // === EXPERIENCE ===
    $exReq = (string) ($job['experience_requirement'] ?? '');
    $requiredConceptKeys = [];
    foreach (hs_extract_items($exReq) as $reqPart) {
        foreach (hs_item_concepts(hs_normalize($reqPart), $concepts) as $k) {
            $requiredConceptKeys[] = $k;
        }
    }
    $requiredConceptKeys = array_values(array_unique($requiredConceptKeys));
    if (empty($requiredConceptKeys)) {
        // Fall back to the required skills when the experience text is generic.
        foreach (hs_extract_items((string) ($job['required_skills'] ?? '')) as $skill) {
            foreach (hs_item_concepts(hs_normalize($skill), $concepts) as $k) {
                $requiredConceptKeys[] = $k;
            }
        }
        $requiredConceptKeys = array_values(array_unique($requiredConceptKeys));
    }
    $exp = hs_score_experience($exReq, $normText, $rawText, $requiredConceptKeys);

    // === OVERALL (weighted, same categories as before) ===
    $skillsScore = $skills['score'];
    $experienceScore = $exp['score'];
    $educationScore = $edu['score'];
    $qualsScore = $quals['score'];

    $overallScore = (int) round(
        ($skillsScore * 0.40) +
        ($experienceScore * 0.25) +
        ($educationScore * 0.25) +
        ($qualsScore * 0.10)
    );
    $overallScore = min(100, max(0, $overallScore));

    // === HARD GATES (unchanged tiers — unrelated/partial content is capped) ===
    $requiredSkillsItems = hs_extract_items((string) ($job['required_skills'] ?? ''));
    if (count($requiredSkillsItems) > 0) {
        if ($skillsScore < 50) {
            $overallScore = min($overallScore, 59);
        } elseif ($skillsScore < 80) {
            $overallScore = min($overallScore, 79);
        }
    }
    if ($edu['exists']) {
        if ($educationScore < 30) {
            $overallScore = min($overallScore, 49);
        } elseif ($educationScore < 80) {
            $overallScore = min($overallScore, 79);
        }
    }
    if ($exReq !== '' && $experienceScore < 50) {
        $overallScore = min($overallScore, 79);
    }

    // Anti keyword-stuffing: a bare copy-paste of the requirements with no
    // employment history cannot reach the Strong tier, and the copied
    // education/qualification wording is discounted too.
    $stuffingFlag = !$experiential && $dutyEvidence === 0 && $skillsScore >= 50;
    if ($stuffingFlag) {
        $overallScore = min($overallScore, 64);
        $educationScore = min($educationScore, 60);
        $qualsScore = min($qualsScore, 60);
    }

    $recommendation = hs_recommendation($overallScore);

    // === CONFIDENCE ===
    $segments = hs_experience_segments($normText, $rawText);
    $strongSegments = 0;
    foreach ($segments as $seg) {
        if (!$seg['weak']) {
            $strongSegments++;
        }
    }
    $confidence = hs_confidence($dutyEvidence, $strongSegments, count($segments), $edu['found'], $experiential, $skills['unclear'] + $quals['unclear']);

    // === MATCHED / PARTIAL / MISSING ===
    $allMatched = array_merge($skills['matched'], $quals['matched']);
    foreach ($edu['matched'] as $em) {
        $allMatched[] = 'Education: ' . $em;
    }
    if ($experienceScore > 0) {
        $allMatched[] = 'Experience: ' . $exp['detail'];
    }

    $allMissing = array_merge($skills['missing'], $quals['missing']);
    foreach ($edu['missing'] as $em) {
        $allMissing[] = 'Education: ' . $em;
    }

    $allPartial = array_merge($skills['partial'], $quals['partial']);

    // === CONCERNS ===
    $concerns = [];
    if ($stuffingFlag) {
        $concerns[] = 'Resume contains the requested requirement wording but no supporting employment history or dates were found — verify actual experience before accepting the score.';
    }
    if (!$edu['found'] && $edu['exists']) {
        $concerns[] = 'No education information was found in the resume or application — education was counted as missing rather than assumed.';
    }
    if ($experienceScore < 50 && $exReq !== '') {
        if ($exp['total_years'] > 0 && $exp['relevant_years'] <= 0) {
            $concerns[] = sprintf('Most listed experience (%.1f yr) does not appear related to the required work — only related experience should count.', $exp['total_years']);
        } elseif ($exp['total_years'] <= 0) {
            $concerns[] = 'No dated employment history was found — relevant experience could not be confirmed.';
        }
    }
    if ($strongSegments === 0 && $exReq !== '') {
        $concerns[] = 'No role-tied employment dates were identifiable; the experience estimate is approximate.';
    }

    // === ANALYSIS TEXT ===
    $analysisParts = [];
    $analysisParts[] = "The applicant's resume was analyzed against the requirements for the {$job['title']} position.";
    if ($skillsScore >= 80) {
        $analysisParts[] = 'The applicant demonstrates strong alignment with the required skills, including related-duty wording where direct phrases were absent.';
    } elseif ($skillsScore >= 50) {
        $analysisParts[] = 'The applicant matches some of the required skills but may have gaps in certain areas.';
    } else {
        $analysisParts[] = 'The applicant has limited matching skills for this position.';
    }
    if ($experienceScore >= 80) {
        $analysisParts[] = 'The experience clearly evidences the required work, with credited relevant years rather than total listed years.';
    } elseif ($experienceScore >= 50) {
        $analysisParts[] = 'The experience level partially meets the requirements.';
    } else {
        $analysisParts[] = 'The experience level may not fully meet the position requirements, or the listed experience does not appear related.';
    }
    if ($educationScore >= 80) {
        $analysisParts[] = 'Educational background meets or exceeds the requirements.';
    } elseif ($educationScore >= 50) {
        $analysisParts[] = 'Educational background partially meets the requirements.';
    } else {
        $analysisParts[] = 'Educational background may not align with the stated requirements.';
    }
    if ($confidence === 'High') {
        $analysisParts[] = 'Screening confidence is HIGH because detailed, dated job evidence was found.';
    } elseif ($confidence === 'Medium') {
        $analysisParts[] = 'Screening confidence is MEDIUM — some evidence is present but additional verification is recommended.';
    } else {
        $analysisParts[] = 'Screening confidence is LOW — resume detail is limited or the resume contains mainly keywords without supporting history.';
    }
    $analysisParts[] = 'This screening is an automated analysis to assist HR in the review process. Final hiring decisions should be made by the HR/Manager.';

    return [
        'success'              => true,
        'overall_score'        => $overallScore,
        'skills_score'         => $skillsScore,
        'experience_score'     => $experienceScore,
        'education_score'      => $educationScore,
        'qualifications_score' => $qualsScore,
        'recommendation'       => $recommendation,
        'matched'              => $allMatched,
        'missing'              => $allMissing,
        'partial'              => $allPartial,
        'evidence'             => array_merge($skills['evidence'], $quals['evidence']),
        'concerns'             => $concerns,
        'confidence'           => $confidence,
        'analysis'             => implode(' ', $analysisParts),
        'job_title'            => (string) ($job['title'] ?? ''),
        'resume_profile'       => [
            'segments_found'        => count($segments),
            'strong_date_segments'  => $strongSegments,
            'duty_evidence_count'   => $dutyEvidence,
            'education_found'       => $edu['found'],
            'experiential_context'  => $experiential,
            'relevant_years'        => $exp['relevant_years'],
            'total_years'           => $exp['total_years'],
        ],
    ];
}