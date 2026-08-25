<?php
/**
 * AI Applicant Resume Screening Engine
 *
 * Compares an applicant's resume against job posting requirements
 * and produces a match score with detailed breakdown.
 */

require_once __DIR__ . '/resume_parser.php';

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
        'bachelor' => ['bachelors degree', 'bachelor degree', 'b.s.', 'b.a.', 'undergraduate'],
        'master' => ['masters degree', 'master degree', 'm.s.', 'm.a.', 'mba', 'graduate degree'],
        'cpa' => ['certified public accountant', 'cpa license'],
        'compTIA' => ['comptia a+', 'comptia network+', 'comptia security+'],
        'help desk' => ['helpdesk', 'technical support', 'it support', 'service desk'],
        'networking' => ['network administration', 'network management', 'lan wan'],
        'troubleshooting' => ['technical troubleshooting', 'fault diagnosis', 'issue resolution'],
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
            if ($formNorm !== '' && mb_strpos($normalized, $formNorm) !== false) {
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
 * Check if a single requirement item is found in the resume text.
 * Uses both exact substring matching and synonym-aware matching.
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

    // Token-based: check if all significant words of the requirement appear
    $reqTokens = tokenize($requirement);
    $significantTokens = array_filter($reqTokens, fn($t) => mb_strlen($t) > 2);
    if (count($significantTokens) > 0) {
        $foundCount = 0;
        foreach ($significantTokens as $token) {
            if (isset($resumeTokenSet[$token]) || mb_strpos($resumeNormalized, $token) !== false) {
                $foundCount++;
            }
        }
        $ratio = $foundCount / count($significantTokens);
        if ($ratio >= 0.8) {
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
                if ($formNorm !== '' && mb_strpos($resumeNormalized, $formNorm) !== false) {
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
 * Score experience match by analyzing year mentions and keywords.
 *
 * @param string $requiredExperience e.g. "2+ years", "3-5 years", "1+ years in operations"
 * @param string $resumeNormalized   Full normalized resume text
 * @return array [int $score, string $detail]
 */
function scoreExperience(string $requiredExperience, string $resumeNormalized): array
{
    $reqNorm = normalizeText($requiredExperience);

    // Extract required years from the requirement
    $reqYears = 0;
    if (preg_match('/(\d+)\+?\s*(?:years?|yrs?)/i', $requiredExperience, $m)) {
        $reqYears = (int) $m[1];
    }

    // Find all year mentions in the resume (e.g., "3 years", "5+ years", "2 yrs")
    $resumeYears = 0;
    if (preg_match_all('/(\d+)\+?\s*(?:years?|yrs?)/i', $resumeNormalized, $yearMatches)) {
        $maxYears = 0;
        foreach ($yearMatches[1] as $ym) {
            $val = (int) $ym;
            if ($val > 0 && $val < 50) {
                $maxYears = max($maxYears, $val);
            }
        }
        $resumeYears = $maxYears;
    }

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
 * Run AI screening on an applicant.
 *
 * @param int $applicantId The applicant ID
 * @param int|null $jobPostingId Optional job posting ID (auto-detected if null)
 * @return array Screening result or error
 */
function runAiScreening(int $applicantId, ?int $jobPostingId = null): array
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
    } elseif ($applicant['job_posting_id']) {
        $stmt2 = db()->prepare('SELECT * FROM job_postings WHERE id = ?');
        $stmt2->execute([$applicant['job_posting_id']]);
        $job = $stmt2->fetch();
    } else {
        // Try to match by position title
        $stmt2 = db()->prepare('SELECT * FROM job_postings WHERE title = ? AND status = ? LIMIT 1');
        $stmt2->execute([$applicant['position_applied'], 'open']);
        $job = $stmt2->fetch();
    }

    if (!$job) {
        return ['error' => 'No matching job posting found. Please link this applicant to a job posting first.'];
    }

    // Extract resume text
    $resumeText = '';
    $resumePath = $applicant['resume_path'] ?? '';
    if ($resumePath !== '') {
        $fullPath = dirname(__DIR__) . '/' . ltrim($resumePath, '/');
        if (file_exists($fullPath)) {
            $resumeText = extractResumeText($fullPath);
        }
    }

    if (trim($resumeText) === '') {
        return [
            'error' => 'Unable to analyze this resume. Please upload a clear PDF, DOC, or DOCX file.',
        ];
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
    $eduItems = [];
    if ($educationReq !== '') {
        $eduItems = extractItems($educationReq);
    }
    // Also check for common education keywords
    $eduKeywords = ['bachelor', 'master', 'degree', 'diploma', 'certification', 'license', 'ph.d', 'doctorate', 'associate'];
    foreach ($eduKeywords as $kw) {
        if (mb_stripos($educationReq, $kw) !== false) {
            $eduItems[] = ucfirst($kw) . ' level education';
        }
    }
    if (empty($eduItems) && $educationReq !== '') {
        $eduItems = [$educationReq];
    }
    [$educationScore, $matchedEducation, $missingEducation] = scoreRequirementList($eduItems, $resumeNormalized, $resumeTokenSet);

    // === EXPERIENCE MATCH ===
    $experienceReq = $job['experience_requirement'] ?? '';
    if ($experienceReq !== '') {
        [$experienceScore, $experienceDetail] = scoreExperience($experienceReq, $resumeNormalized);
    } else {
        $experienceScore = 70;
        $experienceDetail = 'No specific experience requirement defined for this position';
    }

    // === OVERALL SCORE (weighted) ===
    // Skills: 35%, Experience: 30%, Education: 20%, Qualifications: 15%
    $overallScore = (int) round(
        ($skillsScore * 0.35) +
        ($experienceScore * 0.30) +
        ($educationScore * 0.20) +
        ($qualificationsScore * 0.15)
    );
    $overallScore = min(100, max(0, $overallScore));

    // === RECOMMENDATION ===
    if ($overallScore >= 80) {
        $recommendation = 'Strong Match';
    } elseif ($overallScore >= 60) {
        $recommendation = 'Good Match';
    } elseif ($overallScore >= 40) {
        $recommendation = 'Moderate Match';
    } else {
        $recommendation = 'Low Match';
    }

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

    // Store result in database
    $currentUser = getCurrentUser();
    $screenedBy = $currentUser ? (($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? $currentUser['username'] ?? 'System')) : 'System';

    // Delete existing screening for this applicant+job combo
    $delStmt = db()->prepare('DELETE FROM ai_screening WHERE applicant_id = ? AND job_posting_id = ?');
    $delStmt->execute([$applicantId, $job['id']]);

    $insertStmt = db()->prepare(
        'INSERT INTO ai_screening
         (applicant_id, job_posting_id, overall_score, skills_score, experience_score,
          education_score, qualifications_score, recommendation, matched_requirements,
          missing_requirements, ai_analysis, screened_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $matchedJson = json_encode($allMatched, JSON_UNESCAPED_UNICODE);
    $missingJson = json_encode($allMissing, JSON_UNESCAPED_UNICODE);

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
        trim($screenedBy),
    ]);

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

    return [
        'success' => true,
        'overall_score' => $overallScore,
        'skills_score' => $skillsScore,
        'experience_score' => $experienceScore,
        'education_score' => $educationScore,
        'qualifications_score' => $qualificationsScore,
        'recommendation' => $recommendation,
        'matched' => $allMatched,
        'missing' => $allMissing,
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
         ORDER BY s.screened_at DESC
         LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    return $stmt->fetch() ?: null;
}
