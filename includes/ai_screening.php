<?php
/**
 * AI Applicant Resume Screening Engine
 *
 * Compares an applicant's resume against the SPECIFIC job posting they
 * applied for and produces a server-side match score with a detailed
 * breakdown. This powers both:
 *   - the AUTOMATIC analysis that runs right after an application is
 *     submitted (see autoScreenApplicant()), and
 *   - the existing manual "AI Screening" / "Re-run Screening" actions.
 *
 * Providers:
 *   - "local" (default): built-in on-premise screening engine. Deterministic,
 *     no network, no credentials, always available.
 *   - "openai-compatible": remote LLM configured via environment variables
 *     (see config/ai.php). Responses are strictly validated; any failure is
 *     recorded as status "failed" without affecting the application.
 *
 * Status model stored in ai_screening.status:
 *   - pending   — queued / analysis in progress
 *   - analyzed  — a valid server-side result was stored
 *   - failed    — analysis could not be completed (never blocks submission)
 */

require_once __DIR__ . '/resume_parser.php';
require_once __DIR__ . '/../config/ai.php';

const AI_SCREENING_STATUS_PENDING  = 'pending';
const AI_SCREENING_STATUS_ANALYZED = 'analyzed';
const AI_SCREENING_STATUS_FAILED   = 'failed';

const AI_SCREENING_RECO_PENDING     = 'Pending Analysis';
const AI_SCREENING_RECO_UNAVAILABLE = 'Unavailable';

/**
 * Synonym groups for flexible matching.
 * Each canonical form maps to alternative phrasings.
 */
function getSynonymGroups(): array
{
    return [
        'microsoft office' => ['ms office', 'office suite', 'office 365', 'microsoft 365'],
        'microsoft excel' => ['ms excel', 'excel', 'spreadsheet', 'microsoft office excel'],
        'microsoft word' => ['ms word', 'word', 'microsoft office word'],
        'microsoft powerpoint' => ['ms powerpoint', 'powerpoint', 'ppt'],
        'sap' => ['sap erp', 'sap system', 'sap hana'],
        'quickbooks' => ['quick books', 'qb'],
        'javascript' => ['js', 'es6', 'ecmascript'],
        'html' => ['html5', 'hypertext markup'],
        'css' => ['css3', 'cascading style sheets'],
        'sql' => ['mysql', 'mssql', 'plsql', 'tsql', 'structured query language'],
        'project management' => ['pm', 'project planner', 'project planning'],
        'inventory management' => ['inventory control', 'stock management', 'inventory system'],
        'supply chain' => ['logistics', 'procurement', 'supply chain management'],
        'customer service' => ['client service', 'customer support', 'client relations'],
        'human resources' => ['hr', 'personnel', 'people operations'],
        'payroll' => ['payroll processing', 'salary processing'],
        'financial analysis' => ['finance analysis', 'financial analyst', 'financial reporting'],
        'social media' => ['social media marketing', 'social marketing'],
        'seo' => ['search engine optimization'],
        'content creation' => ['content writing', 'content development', 'copywriting'],
        'data analysis' => ['data analytics', 'analytical', 'data-driven'],
        'problem solving' => ['problem-solving', 'analytical thinking', 'critical thinking'],
        'communication skills' => ['communication', 'interpersonal skills', 'verbal and written communication'],
        'team management' => ['leadership', 'team leadership', 'team building'],
        'bachelor' => ['bachelors degree', 'bachelor degree', 'bs', 'ba', 'bsc', 'undergraduate'],
        'master' => ['masters degree', 'master degree', 'ms', 'ma', 'msc', 'mba', 'graduate degree'],
        'cpa' => ['certified public accountant', 'cpa license'],
        'compTIA' => ['comptia a+', 'comptia network+', 'comptia security+'],
        'help desk' => ['helpdesk', 'technical support', 'it support', 'service desk'],
        'networking' => ['network administration', 'network management', 'lan wan'],
        'troubleshooting' => ['technical troubleshooting', 'fault diagnosis', 'issue resolution'],
        'reliable' => ['reliability', 'rely', 'dependable', 'dependability', 'dependably', 'trustworthy', 'trustworthiness'],
        'character' => ['integrity', 'ethics', 'ethical', 'honesty', 'honest', 'values', 'disposition', 'conduct'],
        'moral' => ['ethical', 'ethics', 'integrity', 'honesty', 'values', 'character', 'disposition'],
        'attention' => ['alertness', 'attentiveness', 'observant', 'vigilance', 'vigilant', 'watchful'],
        'observation' => ['observational', 'vigilance', 'alertness', 'surveillance', 'monitoring'],
        'willing' => ['able', 'prepared', 'ready', 'available'],
        'fit' => ['physically fit', 'physical fitness', 'in good health', 'healthy'],
    ];
}

/**
 * Normalize text: lowercase, strip punctuation, collapse whitespace.
 */
function normalizeText(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Tokenize a string into individual meaningful tokens (words/phrases).
 */
function tokenize(string $text): array
{
    $text = normalizeText($text);
    if ($text === '') {
        return [];
    }
    return array_values(array_filter(explode(' ', $text)));
}

/**
 * Check whether a normalized synonym form appears in the normalized resume.
 * Short single tokens ('bs', 'ba', 'js', 'pm', 'hr', ...) must match as whole
 * words so they never trigger inside random text or binary noise.
 */
function synonymFormPresent(string $formNorm, string $resumeNormalized): bool
{
    if ($formNorm === '') {
        return false;
    }
    if (mb_strpos($formNorm, ' ') !== false || mb_strlen($formNorm) >= 4) {
        return mb_strpos($resumeNormalized, $formNorm) !== false;
    }
    return preg_match('/(?<![a-z0-9])' . preg_quote($formNorm, '/') . '(?![a-z0-9])/', $resumeNormalized) === 1;
}

/**
 * Build a lookup set of normalized tokens + synonym-expanded tokens
 * from a source string.
 */
function buildTokenSet(string $text): array
{
    $tokens = tokenize($text);
    $set = array_flip($tokens);
    $normalized = normalizeText($text);

    $synonyms = getSynonymGroups();
    foreach ($synonyms as $canonical => $variants) {
        $canonNorm = normalizeText($canonical);
        $allForms = array_merge([$canonical], $variants);
        $found = false;
        foreach ($allForms as $form) {
            $formNorm = normalizeText($form);
            if (synonymFormPresent($formNorm, $normalized)) {
                $found = true;
                break;
            }
        }
        if ($found) {
            foreach ($allForms as $form) {
                $formNorm = normalizeText($form);
                if ($formNorm !== '') {
                    $set[$formNorm] = true;
                }
            }
        }
    }

    return $set;
}

/**
 * Extract comma-separated or line-separated items from a text field
 * into an array of individual requirement items.
 */
function extractItems(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $items = preg_split('/[,;\n\r]+/', $text);
    $result = [];
    foreach ($items as $item) {
        $item = trim($item);
        if ($item !== '' && mb_strlen($item) >= 2) {
            $result[] = $item;
        }
    }
    return $result;
}

/**
 * Light morphological stemmer so inflectional variants share a root
 * ("reliability"/"reliable", "completed"/"complete", "security"/"secure").
 */
function wordRoot(string $word): string
{
    $w = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $word)));
    if ($w === '' || strlen($w) < 4) {
        return $w;
    }
    $w = preg_replace('/(ingly|ings|edly|ers|ments|ness|ment|ing|ly|ies|ied|es|ed|er|ity|ties|ty|s)$/', '', $w);
    if (strlen($w) >= 5 && substr($w, -1) === 'e') {
        $w = substr($w, 0, -1);
    }
    if (strlen($w) >= 6) {
        $w = substr($w, 0, 6);
    }
    return $w;
}

/**
 * Low-signal words that add no matching power to a requirement item.
 * They are excluded when computing token coverage so connective/soft
 * language ("willing", "required", "at least", "completed") cannot fail a
 * requirement whose real content is present in the resume.
 */
function requirementFillerWords(): array
{
    return [
        'a', 'an', 'and', 'or', 'of', 'the', 'to', 'in', 'with', 'for', 'on',
        'at', 'by', 'from', 'as', 'be', 'is', 'are', 'was', 'were', 'has',
        'have', 'had', 'having', 'do', 'does', 'did',
        'able', 'willing', 'good', 'strong', 'excellent', 'proficient', 'basic',
        'must', 'shall', 'should', 'can', 'may', 'will',
        'required', 'completed', 'completion', 'equivalent', 'least',
        'preferred', 'preferably', 'relevant', 'related', 'per', 'such',
        'other', 'etc', 'like', 'possess', 'possessing', 'demonstrated',
    ];
}

/**
 * Decide whether a single requirement (content) token is present in the
 * resume. Checks in order: exact word, stem-equivalent word, then per-token
 * synonym expansion (if the token belongs to a synonym group, any of the
 * group's other forms may satisfy it).
 */
function requirementTokenFound(string $token, string $resumeNormalized, array $resumeTokenSet): bool
{
    $t = normalizeText($token);
    if ($t === '' || mb_strlen($t) < 2) {
        return false;
    }

    if (isset($resumeTokenSet[$t]) || synonymFormPresent($t, $resumeNormalized)) {
        return true;
    }

    $stem = wordRoot($t);
    if (strlen($stem) >= 5) {
        foreach ($resumeTokenSet as $resumeToken => $_) {
            if (is_int($resumeToken)) {
                continue;
            }
            if (strpos($resumeToken, ' ') !== false) {
                continue;
            }
            if (wordRoot($resumeToken) === $stem) {
                return true;
            }
        }
    }

    $synonyms = getSynonymGroups();
    foreach ($synonyms as $canonical => $variants) {
        $allForms = array_merge([$canonical], $variants);
        $member = false;
        foreach ($allForms as $form) {
            if (normalizeText($form) === $t) {
                $member = true;
                break;
            }
        }
        if (!$member) {
            continue;
        }
        foreach ($allForms as $form) {
            $formNorm = normalizeText($form);
            if ($formNorm === '' || $formNorm === $t) {
                continue;
            }
            if (isset($resumeTokenSet[$formNorm]) || synonymFormPresent($formNorm, $resumeNormalized)) {
                return true;
            }
            if (strlen(wordRoot($formNorm)) >= 5) {
                foreach ($resumeTokenSet as $resumeToken => $_) {
                    if (is_int($resumeToken) || strpos((string) $resumeToken, ' ') !== false) {
                        continue;
                    }
                    if (wordRoot((string) $resumeToken) === wordRoot($formNorm)) {
                        return true;
                    }
                }
            }
        }
    }

    return false;
}

/**
 * Check if a single requirement item is found in the resume text.
 * Uses exact substring matching, fuzzy token coverage, and synonym matching.
 *
 * Returns [bool $found, string $reason]
 */
function matchRequirement(string $requirement, string $resumeNormalized, array $resumeTokenSet): array
{
    $reqNorm = normalizeText($requirement);

    if ($reqNorm === '' || mb_strlen($reqNorm) < 2) {
        return [false, ''];
    }

    // Direct substring match in normalized resume
    if (mb_strpos($resumeNormalized, $reqNorm) !== false) {
        return [true, 'Direct match found in resume'];
    }

    // Token-based: check coverage of significant (non-filler) tokens using
    // stem + synonym-aware matching. Threshold adapts so small requirement
    // items tolerate one dangling word without inflating false negatives.
    $contentTokens = [];
    $fillers = requirementFillerWords();
    foreach (tokenize($requirement) as $token) {
        if (mb_strlen($token) < 2) {
            continue;
        }
        if (in_array($token, $fillers, true)) {
            continue;
        }
        $contentTokens[] = $token;
    }
    if (count($contentTokens) > 0) {
        $foundCount = 0;
        foreach ($contentTokens as $token) {
            if (requirementTokenFound($token, $resumeNormalized, $resumeTokenSet)) {
                $foundCount++;
            }
        }
        $total = count($contentTokens);
        $ratio = $foundCount / $total;
        $threshold = $total <= 3 ? max(0.6, ($total - 1) / $total) : 0.8;
        if ($ratio >= $threshold) {
            return [true, 'Key terms matched in resume'];
        }
    }

    // Synonym expansion match
    $synonyms = getSynonymGroups();
    foreach ($synonyms as $canonical => $variants) {
        $allForms = array_merge([$canonical], $variants);
        $reqContains = false;
        foreach ($allForms as $form) {
            $formNorm = normalizeText($form);
            if ($formNorm !== '' && mb_strpos($reqNorm, $formNorm) !== false) {
                $reqContains = true;
                break;
            }
        }
        if ($reqContains) {
            foreach ($allForms as $form) {
                $formNorm = normalizeText($form);
                if (synonymFormPresent($formNorm, $resumeNormalized)) {
                    return [true, 'Related skill/experience found via synonym matching'];
                }
            }
        }
    }

    return [false, 'Information not found in resume'];
}

/**
 * Score a list of requirement items against the resume.
 *
 * Returns [int $score, array $matched, array $missing]
 */
function scoreRequirementList(array $items, string $resumeNormalized, array $resumeTokenSet): array
{
    if (empty($items)) {
        return [0, [], []];
    }

    $matched = [];
    $missing = [];

    foreach ($items as $item) {
        [$found, $reason] = matchRequirement($item, $resumeNormalized, $resumeTokenSet);
        if ($found) {
            $matched[] = $item;
        } else {
            $missing[] = $item . ' — ' . $reason;
        }
    }

    $total = count($items);
    $score = $total > 0 ? (int) round((count($matched) / $total) * 100) : 0;

    return [$score, $matched, $missing];
}

/**
 * Estimate total tenure (in years) implied by a resume using "N years"/"N
 * months" mentions and date ranges (e.g., "June 2022 - Present", "01/2020 -
 * 03/2024"). Works on raw or normalized text: a light copy that preserves
 * digits and date separators is scanned, and separators are optional so the
 * parser behaves identically when callers pass already-normalized text.
 * Returns the MAXIMUM reliable estimate.
 */
function estimateResumeYears(string $text): float
{
    // Lowercase + collapse whitespace, keeping numbers and date separators.
    $txt = mb_strtolower(trim($text), 'UTF-8');
    $txt = preg_replace(['/[\x{2013}\x{2014}]/u', '/\s*-\s*/'], '-', $txt);
    $txt = preg_replace('/\s+/', ' ', $txt);

    $best = 0.0;

    // "4 years", "5+ years", "2 yrs"
    if (preg_match_all('/(\d+)\+?\s*(?:years?|yrs?)/i', $txt, $ym)) {
        foreach ($ym[1] as $y) {
            $val = (int) $y;
            if ($val > 0 && $val < 50) {
                $best = max($best, (float) $val);
            }
        }
    }

    // "6 months", "18 mos" (counts, e.g., short internships, but capped small).
    // Trailing word boundary so "mo" never matches inside words like
    // "monitored" or "monthly".
    if (preg_match_all('/(\d+)\s*(?:months?|mos)(?![\p{L}\p{N}])/u', $txt, $mm)) {
        foreach ($mm[1] as $m) {
            $val = (int) $m;
            if ($val > 0 && $val < 480) {
                $best = max($best, $val / 12.0);
            }
        }
    }

    // Date ranges: "June 2022 - Present", "Jun 2022 to Present"
    $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
               'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    if (preg_match_all('/([a-z]{3,9})\s+(\d{4})\s*(?:-|to|through)?\s*(?:([a-z]{3,9})\s+(\d{4})|present|now|current)\b/i', $txt, $dateM, PREG_SET_ORDER) > 0) {
        $now = new DateTimeImmutable('today');
        foreach ($dateM as $match) {
            $startMonth = $months[strtolower(substr($match[1], 0, 3))] ?? 0;
            $startYear = (int) $match[2];
            if ($startMonth === 0 || $startYear < 1960 || $startYear > (int) date('Y')) {
                continue;
            }
            if (isset($match[3]) && $match[3] !== '') {
                $endMonth = $months[strtolower(substr($match[3], 0, 3))] ?? 0;
                $endYear = (int) $match[4];
                if ($endMonth === 0 || $endYear < $startYear) {
                    continue;
                }
                $end = new DateTimeImmutable(sprintf('%04d-%02d-01', $endYear, $endMonth));
            } else {
                $end = $now;
            }
            $diff = $startYear * 12 + $startMonth;
            $yrs = (($end->format('Y') * 12 + (int) $end->format('n')) - $diff) / 12.0;
            if ($yrs > 0 && $yrs < 50) {
                $best = max($best, $yrs);
            }
        }
    }

    // Numeric ranges: "01/2020 - 03/2024", "1/2020-3/2024", "1 2020 3 2024"
    if (preg_match_all('#(\d{1,2})\s*[/ -]?\s*(\d{4})\s*(?:-|to|through)?\s*(\d{1,2})?\s*[/ -]?\s*(\d{4})#i', $txt, $numM, PREG_SET_ORDER) > 0) {
        $now = new DateTimeImmutable('today');
        foreach ($numM as $match) {
            $sm = (int) $match[1];
            $sy = (int) $match[2];
            if ($sm < 1 || $sm > 12 || $sy < 1960 || $sy > (int) date('Y')) {
                continue;
            }
            if (isset($match[3]) && $match[3] !== '') {
                $em = (int) $match[3];
                $ey = (int) $match[4];
                if ($em < 1 || $em > 12 || $ey < $sy) {
                    continue;
                }
                $end = new DateTimeImmutable(sprintf('%04d-%02d-01', $ey, $em));
            } else {
                $end = $now;
            }
            $yrs = (($end->format('Y') * 12 + (int) $end->format('n')) - ($sy * 12 + $sm)) / 12.0;
            if ($yrs > 0 && $yrs < 50) {
                $best = max($best, $yrs);
            }
        }
    }

    return round($best, 1);
}

/**
 * Score experience match by analyzing tenure (years, months, date ranges)
 * and job-role keywords.
 *
 * @param string $requiredExperience e.g. "2+ years", "3-5 years", "1+ years in operations"
 * @param string $resumeText         Raw (or normalized) resume text
 * @return array [int $score, string $detail]
 */
function scoreExperience(string $requiredExperience, string $resumeText): array
{
    $resumeNormalized = normalizeText($resumeText);

    // Extract required years from the requirement
    $reqYears = 0;
    if (preg_match('/(\d+)\+?\s*(?:years?|yrs?)/i', $requiredExperience, $m)) {
        $reqYears = (int) $m[1];
    }

    $resumeYears = estimateResumeYears($resumeText);

    // Check for experience-related keywords
    $expKeywords = ['experience', 'worked', 'employment', 'career', 'professional', 'position', 'role', 'job'];
    $hasExpKeywords = false;
    foreach ($expKeywords as $kw) {
        if (mb_strpos($resumeNormalized, $kw) !== false) {
            $hasExpKeywords = true;
            break;
        }
    }

    // Score based on years match
    $yearScore = 0;
    if ($reqYears > 0 && $resumeYears >= $reqYears) {
        $yearScore = 100;
    } elseif ($reqYears > 0 && $resumeYears > 0) {
        $yearScore = min(90, (int) round(($resumeYears / $reqYears) * 100));
    } elseif ($resumeYears > 0 && $reqYears === 0) {
        $yearScore = 70;
    }

    // Check if the requirement context matches resume context
    $contextTokens = tokenize($requiredExperience);
    $contextScore = 0;
    $significantContext = array_filter($contextTokens, fn($t) => mb_strlen($t) > 3 && !in_array($t, ['years', 'year', 'yrs', 'experience', 'required', 'minimum']));
    if (!empty($significantContext)) {
        $foundCtx = 0;
        foreach ($significantContext as $ct) {
            if (mb_strpos($resumeNormalized, $ct) !== false) {
                $foundCtx++;
            }
        }
        $contextScore = count($significantContext) > 0 ? (int) round(($foundCtx / count($significantContext)) * 100) : 50;
    } else {
        $contextScore = 50;
    }

    $finalScore = (int) round(($yearScore * 0.6) + ($contextScore * 0.3) + ($hasExpKeywords ? 10 : 0));
    $finalScore = min(100, max(0, $finalScore));

    $detail = '';
    if ($resumeYears > 0) {
        $detail = "Resume indicates ~{$resumeYears} year(s) of experience";
    } elseif ($hasExpKeywords) {
        $detail = 'Work experience mentioned but duration unclear';
    } else {
        $detail = 'Limited work experience information found in resume';
    }

    return [$finalScore, $detail];
}

/**
 * Map a 0-100 score to the documented match classification:
 *   80-100 Strong Match, 60-79 Moderate Match, 0-59 Low Match.
 */
function screeningClassification(int $score): string
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
 * Check whether a degree-level marker (bachelor/master/bs/... ) occurs in a
 * normalized haystack (a requirement text or a resume).
 */
function degreeLevelFoundInResume(string $levelKey, string $haystackNormalized): bool
{
    $formMap = [
        'bachelor'   => ['bachelor', 'bachelor degree', 'bachelor s degree', 'bachelors degree', 'bs', 'b s', 'bsc', 'b sc', 'ba', 'b a', 'undergraduate'],
        'master'     => ['master', 'masters', 'master degree', 'master s degree', 'msc', 'm sc', 'mba', 'm b a', 'm a', 'graduate degree'],
        'doctorate'  => ['doctorate', 'doctoral', 'phd', 'ph d'],
        'associate'  => ['associate'],
        'diploma'    => ['diploma'],
        'certificate'=> ['certificate', 'certification', 'certified'],
        'license'    => ['license', 'licensure', 'licensed'],
        'highschool'    => ['high school', 'senior high school', 'junior high school', 'secondary', 'secondary education', 'shs', 'highschool', 'high school diploma', 'grade 12'],
        'vocational'    => ['vocational', 'technical vocational', 'tech voc', 'trade school', 'tesda', 'technical education', 'technical school'],
    ];

    $forms = $formMap[$levelKey] ?? [$levelKey];
    $forms = array_merge([$levelKey], $forms);

    foreach ($forms as $form) {
        $formNorm = normalizeText($form);
        if (synonymFormPresent($formNorm, $haystackNormalized)) {
            return true;
        }
    }
    return false;
}

/**
 * Which degree-level keys are mentioned in an education requirement item.
 */
function degreeLevelsInText(string $text): array
{
    $norm = normalizeText($text);
    $levels = [];
    foreach (['bachelor', 'master', 'doctorate', 'associate', 'diploma', 'certificate', 'license', 'highschool', 'vocational'] as $key) {
        if (degreeLevelFoundInResume($key, $norm)) {
            $levels[] = $key;
        }
    }
    return $levels;
}

/**
 * Extract the field-of-study tokens from an education requirement item.
 * Example: "Bachelor's Degree in Marketing, Communications" -> [marketing, communications].
 */
function degreeFieldsIn(string $item): array
{
    $tokens = tokenize($item);
    $stop = ['s', 'in', 'of', 'the', 'a', 'an', 'or', 'and', 'with', 'at', 'from', 'for',
             'level', 'degree', 'education', 'related', 'field', 'diploma', 'certificate',
             'certification', 'license', 'licensure', 'majored', 'major'];

    $levelTokens = ['bachelor', 'bachelors', 'master', 'masters', 'degree', 'bs', 'ba', 'bsc', 'msc', 'mba', 'graduate', 'associate', 'doctorate', 'phd', 'undergraduate'];

    $fields = [];
    $collecting = false;
    foreach ($tokens as $tok) {
        if (!$collecting) {
            if (in_array($tok, $levelTokens, true)) {
                $collecting = true;
            }
            continue;
        }
        if (in_array($tok, $stop, true)) {
            continue;
        }
        if (mb_strlen($tok) >= 4) {
            $fields[] = $tok;
        }
    }

    return array_values(array_unique($fields));
}

/**
 * Extract field-of-study tokens from the resume's own degree phrases, so
 * education relevance is judged by the DEGREE field, not keywords anywhere
 * in the resume (which an unrelated applicant could list casually).
 */
function resumeDegreeFields(string $resumeNormalized): array
{
    $tokens = tokenize($resumeNormalized);
    $stop = ['s', 'in', 'of', 'the', 'a', 'an', 'or', 'and', 'with', 'at', 'from', 'for',
             'level', 'degree', 'education', 'related', 'field', 'diploma', 'certificate',
             'certification', 'license', 'licensure'];

    $levelTokens = ['bachelor', 'bachelors', 'master', 'masters', 'degree', 'bs', 'ba', 'bsc', 'msc', 'mba', 'graduate', 'associate', 'doctorate', 'phd', 'undergraduate', 'licensed', 'certified'];

    $fields = [];
    $capturing = false;
    $captured = 0;
    foreach ($tokens as $tok) {
        if (!$capturing) {
            if (in_array($tok, $levelTokens, true)) {
                $capturing = true;
                $captured = 0;
            }
            continue;
        }
        if (in_array($tok, $stop, true)) {
            continue;
        }
        if (mb_strlen($tok) >= 4) {
            $fields[] = $tok;
            $captured++;
            if ($captured >= 6) {
                $capturing = false;
            }
        }
    }

    return array_values(array_unique($fields));
}

/**
 * Match a single education requirement item.
 * Degree items require BOTH a matching degree level AND a matching field of
 * study; non-degree items (diplomas, certificates) match as plain phrases.
 *
 * @return array [int score, string reason]
 */
function matchEducationItem(string $item, string $resumeNormalized): array
{
    $levels = degreeLevelsInText($item);
    if (count($levels) === 0) {
        [$found, $reason] = matchRequirement($item, $resumeNormalized, buildTokenSet($resumeNormalized));
        return [$found ? 100 : 0, $reason];
    }

    $levelOk = false;
    foreach ($levels as $lv) {
        if (degreeLevelFoundInResume($lv, $resumeNormalized)) {
            $levelOk = true;
            break;
        }
    }

    $simpleLevel = count(array_intersect($levels, ['highschool', 'vocational'])) > 0;
    $reqFields = $simpleLevel ? [] : degreeFieldsIn($item);
    $fieldOk = count($reqFields) === 0;
    if (!$fieldOk) {
        $resumeFields = resumeDegreeFields($resumeNormalized);
        $related = [
            'marketing'      => ['advertising', 'digital marketing', 'public relations', 'communications', 'business administration', 'business', 'commerce', 'ecommerce'],
            'communications' => ['communication', 'journalism', 'public relations', 'marketing'],
            'finance'        => ['accounting', 'accountancy', 'economics', 'business administration'],
            'information technology' => ['computer science', 'information systems', 'software'],
        ];
        foreach ($reqFields as $rf) {
            if (in_array($rf, $resumeFields, true)) {
                $fieldOk = true;
                break;
            }
            if (isset($related[$rf])) {
                foreach ($related[$rf] as $syn) {
                    $synTokens = tokenize($syn);
                    if (count($synTokens) === count(array_intersect($synTokens, $resumeFields))) {
                        $fieldOk = true;
                        break 2;
                    }
                }
            }
        }
    }

    if ($levelOk && $fieldOk) {
        return [100, 'Degree level and field matched in resume'];
    }
    $reasons = [];
    if (!$levelOk) {
        $reasons[] = 'matching degree level not found in resume';
    }
    if (!$fieldOk) {
        $reasons[] = 'no matching field of study found in resume';
    }
    return [25, implode(', ', $reasons)];
}

/**
 * Score education as a single requirement: any acceptable formulation
 * (comma-separated "X, Y, or related field" alternatives) may satisfy it.
 * Returns the best item score (100 = requirement met, 25 = partial, 0 = not).
 *
 * @return array [int score, array matched, array missing, bool requirementExists, string detail]
 */
function scoreEducationMatch(string $educationReq, string $resumeNormalized): array
{
    if (trim($educationReq) === '') {
        return [70, [], [], false, 'No specific education requirement defined for this position'];
    }

    $items = extractItems($educationReq);
    $formulations = [];
    foreach ($items as $it) {
        $it = trim($it);
        if ($it === '' || mb_strlen($it) < 5) {
            continue;
        }
        if (preg_match('/^(and|or|the|a|an|related field|or related field|or related)$/i', $it)) {
            continue;
        }
        $formulations[] = $it;
    }
    if (empty($formulations)) {
        $formulations = [$educationReq];
    }

    $bestScore = 0;
    $matched = [];
    $missing = [];
    foreach ($formulations as $it) {
        [$score, $reason] = matchEducationItem($it, $resumeNormalized);
        if ($score >= 100) {
            $matched[] = $it;
        } else {
            $missing[] = $it . ' — ' . $reason;
        }
        $bestScore = max($bestScore, $score);
    }

    return [$bestScore, $matched, $missing, true, ''];
}

/**
 * Resolve the job posting an applicant applied for.
 * Uses the linked job_posting_id first, then falls back to a title match.
 *
 * @return array|null The job_postings row, or null when none can be resolved.
 */
function resolveScreeningJob(array $applicant): ?array
{
    if (!empty($applicant['job_posting_id'])) {
        $stmt = db()->prepare('SELECT * FROM job_postings WHERE id = ?');
        $stmt->execute([(int) $applicant['job_posting_id']]);
        return $stmt->fetch() ?: null;
    }

    $title = trim((string) ($applicant['position_applied'] ?? ''));
    if ($title === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM job_postings WHERE title = ? AND status = ? LIMIT 1');
    $stmt->execute([$title, 'open']);
    return $stmt->fetch() ?: null;
}

/**
 * Built-in on-premise screening analysis (provider = "local").
 * Pure computation — performs no database writes.
 *
 * @return array Result shape (see runAiScreening) or ['error' => message].
 */
function localScreeningAnalysis(array $job, string $resumeText): array
{
    if (trim($resumeText) === '') {
        return ['error' => 'Unable to analyze this resume. Please upload a clear PDF, DOC, or DOCX file.'];
    }

    // Build resume token set
    $resumeNormalized = normalizeText($resumeText);
    $resumeTokenSet = buildTokenSet($resumeText);

    // === SKILLS MATCH ===
    $requiredSkills = extractItems($job['required_skills'] ?? '');
    [$skillsScore, $matchedSkills, $missingSkills] = scoreRequirementList($requiredSkills, $resumeNormalized, $resumeTokenSet);

    // === QUALIFICATIONS MATCH ===
    $qualifications = extractItems($job['qualifications'] ?? '');
    [$qualificationsScore, $matchedQualifications, $missingQualifications] = scoreRequirementList($qualifications, $resumeNormalized, $resumeTokenSet);

    // === EDUCATION MATCH ===
    $educationReq = $job['education_requirement'] ?? '';
    [$educationScore, $matchedEducation, $missingEducation, $educationRequirementExists] = scoreEducationMatch($educationReq, $resumeNormalized);

    // === EXPERIENCE MATCH ===
    $experienceReq = $job['experience_requirement'] ?? '';
    if ($experienceReq !== '') {
        [$experienceScore, $experienceDetail] = scoreExperience($experienceReq, $resumeText);
    } else {
        $experienceScore = 70;
        $experienceDetail = 'No specific experience requirement defined for this position';
    }

    // === OVERALL SCORE (weighted) ===
    // Required Skills high, Education high, Experience high (job-related),
    // Preferred qualifications moderate. General/unrelated resume content must
    // never push the score up, enforced by the hard gates below.
    $overallScore = (int) round(
        ($skillsScore * 0.40) +
        ($experienceScore * 0.25) +
        ($educationScore * 0.25) +
        ($qualificationsScore * 0.10)
    );
    $overallScore = min(100, max(0, $overallScore));

    // === HARD GATES (unrelated or partial content cannot reach higher tiers) ===
    if (count($requiredSkills) > 0) {
        if ($skillsScore < 50) {
            $overallScore = min($overallScore, 59); // low skill coverage: never Moderate/Strong
        } elseif ($skillsScore < 80) {
            $overallScore = min($overallScore, 79); // incomplete skills: never Strong
        }
    }
    if ($educationRequirementExists) {
        if ($educationScore < 30) {
            $overallScore = min($overallScore, 49); // education unmet/irrelevant: never Moderate/Strong
        } elseif ($educationScore < 80) {
            $overallScore = min($overallScore, 79); // education partial: never Strong
        }
    }
    if ($experienceReq !== '' && $experienceScore < 50) {
        $overallScore = min($overallScore, 79); // insufficient/irrelevant experience: never Strong
    }

    // === RECOMMENDATION (documented classification) ===
    $recommendation = screeningClassification($overallScore);

    // === AI ANALYSIS TEXT ===
    $analysisParts = [];
    $analysisParts[] = "The applicant's resume was analyzed against the requirements for the {$job['title']} position.";

    if ($skillsScore >= 80) {
        $analysisParts[] = "The applicant demonstrates strong alignment with the required technical skills.";
    } elseif ($skillsScore >= 50) {
        $analysisParts[] = "The applicant matches some of the required skills but may have gaps in certain areas.";
    } else {
        $analysisParts[] = "The applicant has limited matching skills for this position.";
    }

    if ($experienceScore >= 80) {
        $analysisParts[] = "The work experience appears well-aligned with the position requirements.";
    } elseif ($experienceScore >= 50) {
        $analysisParts[] = "The experience level partially meets the requirements.";
    } else {
        $analysisParts[] = "The experience level may not fully meet the position requirements.";
    }

    if ($educationScore >= 80) {
        $analysisParts[] = "Educational background meets or exceeds the requirements.";
    } elseif ($educationScore >= 50) {
        $analysisParts[] = "Educational background partially meets the requirements.";
    } else {
        $analysisParts[] = "Educational background may not align with the stated requirements.";
    }

    $analysisParts[] = "This screening is an automated analysis to assist HR in the review process. Final hiring decisions should be made by the HR/Manager.";

    $aiAnalysis = implode(' ', $analysisParts);

    // === COMBINE MATCHED/MISSING ===
    $allMatched = array_merge(
        array_map(fn($s) => 'Skill: ' . $s, $matchedSkills),
        array_map(fn($q) => 'Qualification: ' . $q, $matchedQualifications),
        array_map(fn($e) => 'Education: ' . $e, $matchedEducation),
        $experienceScore > 0 ? ['Experience: ' . $experienceDetail] : []
    );

    $allMissing = array_merge(
        array_map(fn($s) => $s, $missingSkills),
        array_map(fn($q) => $q, $missingQualifications),
        array_map(fn($e) => $e, $missingEducation)
    );

    return [
        'success'              => true,
        'overall_score'        => $overallScore,
        'skills_score'         => $skillsScore,
        'experience_score'     => $experienceScore,
        'education_score'      => $educationScore,
        'qualifications_score' => $qualificationsScore,
        'recommendation'       => $recommendation,
        'matched'              => $allMatched,
        'missing'              => $allMissing,
        'analysis'             => $aiAnalysis,
        'job_title'            => $job['title'],
    ];
}

/**
 * Dispatch analysis to the configured provider.
 * Never modifies the database.
 *
 * @return array Result shape (see runAiScreening) or ['error' => message].
 */
function analyzeResumeWithProvider(array $job, string $resumeText): array
{
    $provider = AI_SCREENING_PROVIDER;

    if ($provider === 'local') {
        return localScreeningAnalysis($job, $resumeText);
    }

    if ($provider === 'openai-compatible') {
        return remoteAnalyzeResume($job, $resumeText);
    }

    return ['error' => 'Unknown AI provider configured: ' . $provider];
}

/**
 * Build the minimized, job-specific prompt sent to a remote provider.
 * Only the job requirement fields and truncated resume text are included —
 * never credentials, application metadata, or internal system details.
 */
function buildScreeningPrompt(array $job, string $resumeText): string
{
    $context = [
        'job_title'             => (string) ($job['title'] ?? ''),
        'required_skills'       => (string) ($job['required_skills'] ?? ''),
        'qualifications'        => (string) ($job['qualifications'] ?? ''),
        'requirements'          => (string) ($job['requirements'] ?? ''),
        'education_requirement' => (string) ($job['education_requirement'] ?? ''),
        'experience_requirement' => (string) ($job['experience_requirement'] ?? ''),
    ];

    $context = array_filter($context, fn($v) => trim((string) $v) !== '');

    $resume = mb_substr($resumeText, 0, AI_SCREENING_MAX_RESUME_CHARS);

    return json_encode([
        'job_posting' => $context,
        'resume_text' => $resume,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Perform a JSON POST over HTTP with a hard timeout.
 *
 * @return array{0:int,1:string} [HTTP status code, response body].
 * @throws RuntimeException on transport failure.
 */
function httpPostJson(string $url, array $payload, int $timeoutSeconds): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . AI_SCREENING_API_KEY,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialise cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => max(3, min(10, $timeoutSeconds)),
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('AI provider request failed: ' . $error);
        }
        return [$status, (string) $response];
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => $timeoutSeconds,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('AI provider request failed.');
    }
    $status = 200;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    return [$status, $response];
}

/**
 * Strictly validate a remote AI screening response.
 *
 * Expected shape (either top-level or under a "match" object):
 *   {
 *     "score": 87,                       // required, numeric 0-100
 *     "classification": "Strong Match",  // optional; derived from score if absent
 *     "summary": "...",                  // required, reasonable length
 *     "matched": ["..."],                // optional
 *     "missing": ["..."]                 // optional
 *   }
 *
 * @return array Result fields (overall_score, recommendation, analysis,
 *         matched, missing) or ['error' => message] when invalid.
 */
function parseAiScreeningResponse(string $raw, string $jobTitle = ''): array
{
    $json = trim($raw);

    // Strip Markdown code fences some providers add.
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $m)) {
        $json = trim($m[1]);
    }
    if ($json === '') {
        return ['error' => 'AI response is empty.'];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['error' => 'AI response is not valid JSON.'];
    }

    $match = $data['match'] ?? $data;
    if (!is_array($match)) {
        return ['error' => 'AI response structure is invalid.'];
    }

    // ---- Score: must exist and be numeric, clamped to 0-100 ----
    $scoreRaw = $match['score'] ?? null;
    if ($scoreRaw === null || !is_numeric($scoreRaw)) {
        return ['error' => 'AI response is missing a valid numeric score.'];
    }
    $score = (int) round((float) $scoreRaw);
    if ($score < 0 || $score > 100) {
        return ['error' => 'AI response score is outside the 0-100 range.'];
    }

    // ---- Classification: must be an allowed value or derived from the score ----
    // The label is ALWAYS reconciled with the numeric score so an inconsistent
    // provider response (e.g. score 40 labelled "Strong Match") cannot distort
    // the final recommendation.
    $classification = trim((string) ($match['classification'] ?? ''));
    $allowed = ['Strong Match', 'Moderate Match', 'Low Match', 'Good Match'];
    if ($classification === '' || !in_array($classification, $allowed, true) || $classification !== screeningClassification($score)) {
        $classification = screeningClassification($score);
    }

    // ---- Summary: must exist and be a reasonable length ----
    $summary = trim((string) ($match['summary'] ?? ''));
    if ($summary === '' || mb_strlen($summary) < 10) {
        return ['error' => 'AI response is missing a usable summary.'];
    }
    if (mb_strlen($summary) > AI_SCREENING_MAX_SUMMARY_CHARS) {
        $summary = mb_substr($summary, 0, AI_SCREENING_MAX_SUMMARY_CHARS);
    }

    // ---- Matched / missing: optional arrays of short strings ----
    $matched = $match['matched'] ?? [];
    $missing = $match['missing'] ?? [];
    foreach (['matched' => &$matched, 'missing' => &$missing] as $key => &$list) {
        if ($list === null) {
            $list = [];
        }
        if (!is_array($list)) {
            return ['error' => 'AI response field "' . $key . '" must be an array.'];
        }
        $clean = [];
        foreach ($list as $item) {
            $item = trim((string) $item);
            if ($item !== '' && mb_strlen($item) <= 200) {
                $clean[] = $item;
            }
        }
        $list = array_slice($clean, 0, 40);
    }
    unset($list); // break the loop reference; $matched/$missing keep their cleaned values

    $analysis = $summary;
    if (in_array(AI_SCREENING_PROVIDER, ['openai-compatible'], true)) {
        $analysis = $analysis . ' This screening is an automated analysis to assist HR in the review process. Final hiring decisions should be made by the HR/Manager.';
    }

    return [
        'overall_score'  => $score,
        'recommendation' => $classification,
        'analysis'       => $analysis,
        'matched'        => $matched,
        'missing'        => $missing,
    ];
}

/**
 * Analyze the resume with an OpenAI-compatible chat-completions provider.
 * Fails safely (returns an error array) on any transport/validation problem.
 */
function remoteAnalyzeResume(array $job, string $resumeText): array
{
    if (AI_SCREENING_API_URL === '' || AI_SCREENING_API_KEY === '') {
        return ['error' => 'AI provider is not configured. Set AI_SCREENING_API_URL and AI_SCREENING_API_KEY.'];
    }

    $system = 'You are an HR screening assistant. Compare the given resume against the job requirements '
        . 'and respond with ONLY valid JSON in this exact shape: '
        . '{"match":{"score":0,"classification":"Strong Match|Moderate Match|Low Match",'
        . '"summary":"short explanation","matched":["..."],"missing":["..."]}}. '
        . 'Score is 0-100. classification: 80-100=Strong Match, 60-79=Moderate Match, 0-59=Low Match. '
        . 'Do not include any text outside the JSON object.';

    $payload = [
        'model'       => AI_SCREENING_MODEL,
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => buildScreeningPrompt($job, $resumeText)],
        ],
        'temperature' => 0,
    ];

    try {
        [$status, $body] = httpPostJson(AI_SCREENING_API_URL, $payload, AI_SCREENING_TIMEOUT_SECONDS);
    } catch (Throwable $e) {
        return ['error' => 'AI provider request failed: ' . $e->getMessage()];
    }

    if ($status < 200 || $status >= 300) {
        return ['error' => 'AI provider returned HTTP ' . $status . '.'];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['error' => 'AI provider returned invalid JSON.'];
    }

    // Extract the chat content; some providers already return a structured body.
    $content = null;
    if (isset($decoded['choices'][0]['message']['content'])) {
        $content = trim((string) $decoded['choices'][0]['message']['content']);
    }
    if (($content === null || $content === '') && isset($decoded['match'])) {
        $content = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($content === null || $content === '') {
        return ['error' => 'AI provider returned an empty analysis.'];
    }

    $parsed = parseAiScreeningResponse($content, (string) ($job['title'] ?? ''));
    if (isset($parsed['error'])) {
        return ['error' => $parsed['error']];
    }

    // Remote providers return an overall match only; per-category breakdown is
    // a local-engine feature, so leave those fields null (UI renders "—").
    return [
        'success'              => true,
        'overall_score'        => $parsed['overall_score'],
        'skills_score'         => null,
        'experience_score'     => null,
        'education_score'      => null,
        'qualifications_score' => null,
        'recommendation'       => $parsed['recommendation'],
        'matched'              => $parsed['matched'],
        'missing'              => $parsed['missing'],
        'analysis'             => $parsed['analysis'],
        'job_title'            => $job['title'],
    ];
}

/**
 * Create a "pending" screening row for an applicant so the UI can show
 * "Analyzing..." while the (usually fast, local) analysis runs. No-op when
 * a row already exists for the applicant+job combination.
 */
function createPendingScreeningRow(int $applicantId, int $jobPostingId): void
{
    $stmt = db()->prepare('SELECT id FROM ai_screening WHERE applicant_id = ? AND job_posting_id = ? LIMIT 1');
    $stmt->execute([$applicantId, $jobPostingId]);
    if ($stmt->fetch()) {
        return;
    }

    db()->prepare(
        'INSERT INTO ai_screening
            (applicant_id, job_posting_id, overall_score, skills_score,
             experience_score, education_score, qualifications_score,
             recommendation, status)
         VALUES (?, ?, 0, 0, 0, 0, 0, ?, ?)'
    )->execute([$applicantId, $jobPostingId, AI_SCREENING_RECO_PENDING, AI_SCREENING_STATUS_PENDING]);
}

/**
 * Mark an applicant's screening as failed/unavailable. Used when the AI
 * provider errors, the resume cannot be read, or the response is invalid.
 * Never exposes the technical message through the UI.
 */
function markScreeningFailed(int $applicantId, int $jobPostingId, string $message): void
{
    $message = mb_substr($message, 0, 500);

    // Keep exactly one row per applicant+job: mirror the success path's
    // delete-then-insert so repeated failures never accumulate duplicates.
    db()->prepare('DELETE FROM ai_screening WHERE applicant_id = ? AND job_posting_id = ?')
        ->execute([$applicantId, $jobPostingId]);

    db()->prepare(
        'INSERT INTO ai_screening
            (applicant_id, job_posting_id, overall_score, skills_score,
             experience_score, education_score, qualifications_score,
             recommendation, status, error_message, ai_analysis)
         VALUES (?, ?, 0, 0, 0, 0, 0, ?, ?, ?, ?)'
    )->execute([
        $applicantId,
        $jobPostingId,
        AI_SCREENING_RECO_UNAVAILABLE,
        AI_SCREENING_STATUS_FAILED,
        $message,
        'Automatic analysis could not be completed. The applicant record remains intact.',
    ]);

    error_log('[ai_screening] applicant_id=' . $applicantId . ' job_posting_id=' . $jobPostingId . ' failed: ' . $message);
}

/**
 * AUTOMATIC screening trigger — called right after a successful application
 * submission. It NEVER throws and NEVER blocks the application: on any error
 * the analysis is recorded as failed/unavailable and the app stays intact.
 *
 * @return array Same shape as runAiScreening() (ignored by callers).
 */
function autoScreenApplicant(int $applicantId): array
{
    try {
        $stmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
        $stmt->execute([$applicantId]);
        $applicant = $stmt->fetch();
        if (!$applicant) {
            return ['error' => 'Applicant not found.'];
        }

        $job = resolveScreeningJob($applicant);
        if (!$job) {
            error_log('[ai_screening] auto screening skipped for applicant_id=' . $applicantId . ': no job posting could be resolved.');
            return ['error' => 'No matching job posting found.'];
        }

        // Surface an immediate "Analyzing..." state before the analysis runs.
        createPendingScreeningRow($applicantId, (int) $job['id']);

        // advanceApplicant=false keeps the applicant's own workflow status
        // untouched — the AI score never automatically accepts/rejects or
        // re-categorizes an application.
        $result = runAiScreening($applicantId, (int) $job['id'], false, 'System');

        if (isset($result['error'])) {
            markScreeningFailed($applicantId, (int) $job['id'], $result['error']);
            return $result;
        }

        return $result;
    } catch (Throwable $e) {
        error_log('[ai_screening] auto screening failed for applicant_id=' . $applicantId . ': ' . $e->getMessage());
        $job = null;
        try {
            $stmt = db()->prepare('SELECT job_posting_id FROM applicants WHERE id = ?');
            $stmt->execute([$applicantId]);
            if ($row = $stmt->fetch()) {
                $job = $row;
            }
        } catch (Throwable $ignored) {
            // best effort only
        }
        if (!empty($job['job_posting_id'])) {
            try {
                markScreeningFailed($applicantId, (int) $job['job_posting_id'], 'An unexpected error occurred during AI screening.');
            } catch (Throwable $ignored) {
                // best effort only
            }
        }
        return ['error' => 'An unexpected error occurred during AI screening.'];
    }
}

/**
 * Run AI screening on an applicant.
 *
 * @param int $applicantId The applicant ID
 * @param int|null $jobPostingId Optional job posting ID (auto-detected if null)
 * @param bool $advanceApplicant Move 'new' applicants to 'screening' and
 *        auto-link a missing job posting (existing manual-flow behavior).
 *        Automatic submissions pass false so the AI score never changes the
 *        applicant's recruitment status.
 * @param string|null $screenedBy Override the "screened by" label (the
 *        automatic trigger records "System").
 * @return array Screening result or error
 */
function runAiScreening(int $applicantId, ?int $jobPostingId = null, bool $advanceApplicant = true, ?string $screenedBy = null): array
{
    // Load applicant
    $stmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $applicant = $stmt->fetch();
    if (!$applicant) {
        return ['error' => 'Applicant not found.'];
    }

    // Load job posting
    if ($jobPostingId) {
        $stmt2 = db()->prepare('SELECT * FROM job_postings WHERE id = ?');
        $stmt2->execute([$jobPostingId]);
        $job = $stmt2->fetch();
        if (!$job) {
            return ['error' => 'The linked job posting no longer exists.'];
        }
    } else {
        $job = resolveScreeningJob($applicant);
    }

    if (!$job) {
        return ['error' => 'No matching job posting found. Please link this applicant to a job posting first.'];
    }

    // Extract resume text
    $resumeText = '';
    $resumePath = $applicant['resume_path'] ?? '';
    $resumeAbsPath = '';
    if ($resumePath !== '') {
        $resumeAbsPath = dirname(__DIR__) . '/' . ltrim($resumePath, '/');
        if (file_exists($resumeAbsPath)) {
            $resumeText = extractResumeText($resumeAbsPath);
        }
    }

    if (trim($resumeText) === '') {
        $diag = diagnoseResumeReadFailure($resumeAbsPath, '');
        $message = $diag['reason'] ?: 'Unable to analyze this resume. Please upload a clear PDF, DOC, or DOCX file.';
        if (!empty($diag['hint'])) {
            $message .= ' ' . $diag['hint'];
        }
        return ['error' => $message];
    }

    // Run the configured provider (local engine by default).
    $analysis = analyzeResumeWithProvider($job, $resumeText);
    if (isset($analysis['error'])) {
        return ['error' => $analysis['error']];
    }

    $overallScore           = (int) $analysis['overall_score'];
    $skillsScore            = $analysis['skills_score'];
    $experienceScore        = $analysis['experience_score'];
    $educationScore         = $analysis['education_score'];
    $qualificationsScore    = $analysis['qualifications_score'];
    $recommendation         = $analysis['recommendation'];
    $matched                = $analysis['matched'] ?? [];
    $missing                = $analysis['missing'] ?? [];
    $aiAnalysis             = $analysis['analysis'];

    // Store result in database
    if ($screenedBy === null) {
        $currentUser = getCurrentUser();
        $screenedBy = $currentUser ? (($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? $currentUser['username'] ?? 'System')) : 'System';
    }
    $screenedBy = trim((string) $screenedBy);

    // Delete existing screening for this applicant+job combo
    $delStmt = db()->prepare('DELETE FROM ai_screening WHERE applicant_id = ? AND job_posting_id = ?');
    $delStmt->execute([$applicantId, $job['id']]);

    $insertStmt = db()->prepare(
        'INSERT INTO ai_screening
         (applicant_id, job_posting_id, overall_score, skills_score, experience_score,
          education_score, qualifications_score, recommendation, matched_requirements,
          missing_requirements, ai_analysis, screened_by, status, error_message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $matchedJson = json_encode($matched, JSON_UNESCAPED_UNICODE);
    $missingJson = json_encode($missing, JSON_UNESCAPED_UNICODE);

    $insertStmt->execute([
        $applicantId,
        $job['id'],
        $overallScore,
        $skillsScore,
        $experienceScore,
        $educationScore,
        $qualificationsScore,
        $recommendation,
        $matchedJson,
        $missingJson,
        $aiAnalysis,
        $screenedBy,
        AI_SCREENING_STATUS_ANALYZED,
        null,
    ]);

    if ($advanceApplicant) {
        // Update applicant status to 'screening' if currently 'new'
        if ($applicant['status'] === 'new') {
            $updStmt = db()->prepare('UPDATE applicants SET status = ? WHERE id = ? AND status = ?');
            $updStmt->execute(['screening', $applicantId, 'new']);
        }

        // Auto-link applicant to job posting if not already linked
        if (!$applicant['job_posting_id']) {
            $linkStmt = db()->prepare('UPDATE applicants SET job_posting_id = ? WHERE id = ? AND job_posting_id IS NULL');
            $linkStmt->execute([$job['id'], $applicantId]);
        }
    }

    return [
        'success' => true,
        'overall_score' => $overallScore,
        'skills_score' => $skillsScore,
        'experience_score' => $experienceScore,
        'education_score' => $educationScore,
        'qualifications_score' => $qualificationsScore,
        'recommendation' => $recommendation,
        'matched' => $matched,
        'missing' => $missing,
        'analysis' => $aiAnalysis,
        'job_title' => $job['title'],
        'applicant_name' => $applicant['first_name'] . ' ' . $applicant['last_name'],
    ];
}

/**
 * Get the latest screening result for an applicant.
 */
function getScreeningResult(int $applicantId): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, j.title AS job_title, j.required_skills, j.qualifications,
                j.education_requirement, j.experience_requirement
         FROM ai_screening s
         JOIN job_postings j ON s.job_posting_id = j.id
         WHERE s.applicant_id = ?
         ORDER BY s.screened_at DESC, s.id DESC
         LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    return $stmt->fetch() ?: null;
}