<?php
/**
 * HR1 examination helpers.
 *
 * This is the HR1-side of the future HR3 examination integration. HR1 owns:
 *   - exam references (an `exams` row bound to a Job/Position)
 *   - exam assignments (`exam_assignments`) placed against an eligible applicant
 *   - the returned-result ledger (`exam_results`)
 *
 * HR1 does NOT store or implement HR3's exam content, questions, correct
 * answers, or scoring engine. All eligibility checks must run server-side;
 * never trust URL params, hidden fields, JavaScript, POST data, or direct
 * API calls.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security_log.php';

/** Status values that mean "passed initial screening" → exam-eligible. */
function examPassedScreeningStatuses(): array
{
    return ['accepted', 'passed_screening'];
}

/**
 * Whether an applicant is eligible to be assigned an examination.
 * Server-side rule: the applicant must have passed initial screening AND
 * be linked to a Job/Position (the applied job determines the exam).
 *
 * @param array $applicant A row from the `applicants` table.
 */
function applicantExamEligible(array $applicant): bool
{
    if (empty($applicant)) {
        return false;
    }
    if (!in_array((string) ($applicant['status'] ?? ''), examPassedScreeningStatuses(), true)) {
        return false;
    }
    return !empty($applicant['job_posting_id']);
}

/**
 * Active (assignable) exams for a given Job/Position.
 * The applied Job/Position determines the exam — an exam may only be
 * assigned to an applicant whose job_posting_id matches the exam's.
 *
 * @return array Rows from `exams`.
 */
function activeExamsForJob(?int $jobId): array
{
    if (!$jobId) {
        return [];
    }
    $stmt = db()->prepare(
        "SELECT e.*, j.title AS job_title, j.job_code,
                (SELECT COUNT(*) FROM exam_assignments a WHERE a.exam_id = e.id) AS assignments_count
         FROM exams e
         LEFT JOIN job_postings j ON j.id = e.job_posting_id
         WHERE e.job_posting_id = ? AND e.status = 'active'
         ORDER BY e.title ASC"
    );
    $stmt->execute([$jobId]);
    return $stmt->fetchAll();
}

/**
 * All active exams (HR/Admin exam list).
 * @return array Rows from `exams` with job info.
 */
function allExams(): array
{
    return db()->query(
        "SELECT e.*, j.title AS job_title, j.job_code,
                d.name AS department_name,
                (SELECT COUNT(*) FROM exam_assignments a WHERE a.exam_id = e.id) AS assignments_count,
                (SELECT COUNT(*) FROM exam_assignments a WHERE a.exam_id = e.id AND a.status = 'completed') AS completed_count
         FROM exams e
         LEFT JOIN job_postings j ON j.id = e.job_posting_id
         LEFT JOIN departments d ON d.id = j.department_id
         ORDER BY e.created_at DESC"
    )->fetchAll();
}

/** Fetch a single exam by id (with job info), or null. */
function findExam(int $id): ?array
{
    $stmt = db()->prepare(
        "SELECT e.*, j.title AS job_title, j.job_code, d.name AS department_name
         FROM exams e
         LEFT JOIN job_postings j ON j.id = e.job_posting_id
         LEFT JOIN departments d ON d.id = j.department_id
         WHERE e.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Whether an exam has a usable provider examination URL (supplied by HR3).
 * The Online Examination email is only sent when this returns true.
 * @param array $exam A row from the `exams` table.
 */
function examHasProviderUrl(array $exam): bool
{
    $url = trim((string) ($exam['exam_url'] ?? ''));
    if ($url === '') {
        return false;
    }
    if (strlen($url) > 500) {
        return false;
    }
    if (strcasecmp((string) parse_url($url, PHP_URL_SCHEME), 'https') !== 0) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/** Generate a strong opaque assignment reference (Part 2: assignment_ref). */
function examAssignmentToken(): string
{
    return 'exm_' . bin2hex(random_bytes(24));
}

/**
 * Ensure the applicant has a secure exam access token so the Online
 * Examination email can carry a protected, no-login access link.
 *
 * Reuses the existing opaque token mechanism (exm_[48 hex]) and the existing
 * pending-request behavior. If the applicant already has an exam assignment
 * (Pending Request with exam_id=NULL OR a real assigned exam), its existing
 * access_token is returned so the SAME token is used everywhere. Otherwise a
 * Pending Request row (exam_id=NULL, no provider URL) is created — no fake
 * exam/HR3 URL is ever invented.
 *
 * @param int $applicantId The applicant's id.
 * @param int $assignedBy  The HR/manager user performing the action.
 * @return string|null The secure access token, or null on failure.
 */
function ensureApplicantExamToken(int $applicantId, int $assignedBy): ?string
{
    $stmt = db()->prepare(
        'SELECT access_token FROM exam_assignments
         WHERE applicant_id = ? AND access_token IS NOT NULL
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    $existing = $stmt->fetch();
    if ($existing && !empty($existing['access_token'])) {
        return $existing['access_token'];
    }

    $res = requestExamForApplicantByApplicantId($applicantId, $assignedBy);
    return is_null($res) ? null : $res;
}

/**
 * Create a Pending Exam Request (exam_id=NULL) for an applicant and return the
 * secure access token. Mirrors requestExamForApplicant() but returns the token
 * so callers can build the no-login access link. Sends NO email.
 *
 * @param int $applicantId The applicant's id.
 * @param int $assignedBy  The HR/manager user id.
 * @return string|null The new secure token, or null if it cannot be created.
 */
function requestExamForApplicantByApplicantId(int $applicantId, int $assignedBy): ?string
{
    $aStmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
    $aStmt->execute([$applicantId]);
    $applicant = $aStmt->fetch();
    if (!$applicant) {
        return null;
    }
    if (!applicantExamEligible($applicant)) {
        return null;
    }
    $jobId = (int) ($applicant['job_posting_id'] ?? 0);
    if ($jobId <= 0) {
        return null;
    }

    $token = examAssignmentToken();
    try {
        db()->prepare(
            'INSERT INTO exam_assignments (exam_id, applicant_id, job_posting_id, access_token, status, assigned_by)
             VALUES (NULL, ?, ?, ?, ?, ?)'
        )->execute([
            $applicantId,
            $jobId,
            $token,
            'assigned',
            $assignedBy,
        ]);
    } catch (Throwable $e) {
        error_log('requestExamForApplicantByApplicantId failed: ' . $e->getMessage());
        return null;
    }
    return $token;
}

/**
 * Load assignments for an exam with the applicant's details.
 * @return array assignment rows enriched with applicant + result info.
 */
function examAssignments(int $examId): array
{
    $stmt = db()->prepare(
        "SELECT ea.*, a.applicant_no, a.first_name, a.last_name, a.email,
                a.position_applied, a.status AS applicant_status,
                j.title AS job_title,
                (SELECT er.passed FROM exam_results er
                   JOIN exam_attempts at ON at.id = er.attempt_id
                  WHERE at.assignment_id = ea.id
                  ORDER BY er.id DESC LIMIT 1) AS result_passed,
                (SELECT er.percentage FROM exam_results er
                   JOIN exam_attempts at ON at.id = er.attempt_id
                  WHERE at.assignment_id = ea.id
                  ORDER BY er.id DESC LIMIT 1) AS result_percentage,
                (SELECT er.result_status FROM exam_results er
                   JOIN exam_attempts at ON at.id = er.attempt_id
                  WHERE at.assignment_id = ea.id
                  ORDER BY er.id DESC LIMIT 1) AS result_status
         FROM exam_assignments ea
         JOIN applicants a ON a.id = ea.applicant_id
         LEFT JOIN job_postings j ON j.id = ea.job_posting_id
         WHERE ea.exam_id = ?
         ORDER BY ea.created_at DESC"
    );
    $stmt->execute([$examId]);
    return $stmt->fetchAll();
}

/** Assign an exam to an applicant. Returns [ok, message], never throws on dup. */
function assignExamToApplicant(int $examId, int $applicantId, int $assignedBy): array
{
    $exam = findExam($examId);
    $aStmt = db()->prepare('SELECT * FROM applicants WHERE id = ?');
    $aStmt->execute([$applicantId]);
    $applicant = $aStmt->fetch();

    if (!$exam || !$applicant) {
        return [false, 'Exam or applicant not found.'];
    }
    if ($exam['status'] !== 'active') {
        return [false, 'This examination is not active and cannot be assigned.'];
    }
    if (!applicantExamEligible($applicant)) {
        return [false, 'Only applicants who passed initial screening may be assigned an examination.'];
    }
    if ((int) $exam['job_posting_id'] !== (int) $applicant['job_posting_id']) {
        return [false, 'This examination does not match the job the applicant applied for. Exam assignments must correspond to the applicant\'s applied Job/Position.'];
    }

    $dup = db()->prepare('SELECT id FROM exam_assignments WHERE exam_id = ? AND applicant_id = ? LIMIT 1');
    $dup->execute([$examId, $applicantId]);
    if ($dup->fetch()) {
        return [false, 'An examination has already been assigned to this applicant for this job.'];
    }

    $token = examAssignmentToken();
    $insert = db()->prepare(
        'INSERT INTO exam_assignments (exam_id, applicant_id, job_posting_id, access_token, status, assigned_by)
         VALUES (?,?,?,?,?,?)'
    );
    $insert->execute([
        $examId,
        $applicantId,
        $applicant['job_posting_id'],
        $token,
        'assigned',
        $assignedBy,
    ]);
    $assignmentId = (int) db()->lastInsertId();

    // The Online Examination email is sent ONLY when the assigned exam has a
    // real provider URL (supplied by HR3 in the future). Until HR3 provides a
    // valid exam_url, the assignment is recorded as a pending assignment and
    // left waiting for the real exam — the email is NOT sent yet and
    // exam_email_sent_at stays NULL so it can be sent later.
    $hasProviderUrl = examHasProviderUrl($exam);

    if (!$hasProviderUrl) {
        securityLog(
            'exam_assignment_waiting_provider_url',
            "assignment_id={$assignmentId} applicant_id={$applicantId} exam_id={$examId}",
            $assignedBy
        );
        return [true, 'Examination assignment recorded for ' . $applicant['first_name'] . ' ' . $applicant['last_name'] . ' (waiting for the provider examination URL).'];
    }

    // Send the exam-assignment email to the applicant's registered email
    // (FROM HR1, never HR3) guarded by exam_email_sent_at to prevent duplicates.
    // The link is a tokenized self-serve page (opaque access_token only — the
    // applicant has NO HR1 account, so no login is required and no DB ids,
    // applicant ids, API keys, passwords or HR3 details are exposed).
    require_once __DIR__ . '/mail.php';
    $examLink = (defined('BASE_URL') ? BASE_URL : '/HR1')
        . '/modules/applicant/exam_access.php?token=' . urlencode($token);
    $applicantName = trim($applicant['first_name'] . ' ' . $applicant['last_name']);
    $emailSent = sendExamAssignmentEmail(
        $applicant['email'],
        $applicantName,
        $applicant['position_applied'],
        $examLink
    );
    if ($emailSent) {
        db()->prepare('UPDATE exam_assignments SET exam_email_sent_at = NOW() WHERE id = ?')
            ->execute([$assignmentId]);
        securityLog(
            'exam_assignment_email_sent',
            "assignment_id={$assignmentId} applicant_id={$applicantId} exam_id={$examId}",
            $assignedBy
        );
    } else {
        securityLog(
            'exam_assignment_email_failed',
            "assignment_id={$assignmentId} applicant_id={$applicantId} exam_id={$examId}",
            $assignedBy
        );
    }

    return [true, 'Examination assigned to ' . $applicant['first_name'] . ' ' . $applicant['last_name'] . '.'];
}

/**
 * Request an examination for an eligible applicant WITHOUT a concrete exam
 * reference yet (exam_id = NULL). This represents the "Exam Assignment /
 * Request" stage of the HR1 <-> HR3 architecture: the applicant passed
 * initial screening and is eligible, so HR1 records a request tied to the
 * applicant's Applied Job/Position. Future HR3 (or a later exam reference)
 * fulfils it; a null-exam request cannot receive a result until an exam
 * reference with a passing score exists (see recordExamResult).
 *
 * @param array $applicant A row from the `applicants` table.
 * @param int   $assignedBy The logged-in HR/manager user id.
 */
function requestExamForApplicant(array $applicant, int $assignedBy): array
{
    if (empty($applicant)) {
        return [false, 'Applicant not found.'];
    }
    if (!applicantExamEligible($applicant)) {
        return [false, 'Only applicants who passed initial screening may be assigned an examination.'];
    }
    $jobId = (int) ($applicant['job_posting_id'] ?? 0);
    if ($jobId <= 0) {
        return [false, 'The applicant is not linked to a Job/Position, so an examination cannot be requested.'];
    }

    // De-duplicate: one request per applicant (any existing assignment —
    // to a specific exam OR a pending request — blocks a new one).
    $dup = db()->prepare(
        'SELECT ea.id FROM exam_assignments ea WHERE ea.applicant_id = ? LIMIT 1'
    );
    $dup->execute([(int) $applicant['id']]);
    if ($dup->fetch()) {
        return [false, 'An examination has already been assigned/requested for this applicant.'];
    }

    $token = examAssignmentToken();
    try {
        db()->prepare(
            'INSERT INTO exam_assignments (exam_id, applicant_id, job_posting_id, access_token, status, assigned_by)
             VALUES (NULL, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $applicant['id'],
            $jobId,
            $token,
            'assigned',
            $assignedBy,
        ]);
    } catch (Throwable $e) {
        error_log('requestExamForApplicant failed: ' . $e->getMessage());
        return [false, 'The examination request could not be created.'];
    }
    return [true, 'Examination requested for ' . $applicant['first_name'] . ' ' . $applicant['last_name'] . ' (awaiting external exam).'];
}

/**
 * The applicants that are eligible for a given exam's job, for the assign form.
 * @return array applicants (passed screening, matching job, not already assigned)
 */
function eligibleApplicantsForExam(int $examId): array
{
    $exam = findExam($examId);
    if (!$exam) {
        return [];
    }
    $jobId = (int) $exam['job_posting_id'];
    $in = implode(',', array_map(static fn ($s) => db()->quote($s), examPassedScreeningStatuses()));
    $stmt = db()->prepare(
        "SELECT a.* FROM applicants a
         WHERE a.job_posting_id = ?
           AND a.status IN ($in)
           AND NOT EXISTS (SELECT 1 FROM exam_assignments ea
                           WHERE ea.applicant_id = a.id AND ea.exam_id = ?)
         ORDER BY a.applied_date DESC"
    );
    $stmt->execute([$jobId, $examId]);
    return $stmt->fetchAll();
}

// =====================================================================
// PART 4 — EXAM RESULT -> FINAL INTERVIEW (HR1-side ingest & validation)
//
// HR1 receives a result from the future external HR3 provider and must
// associate it with the correct applicant / application / job / exam /
// attempt, validate it server-side (never trust the external `passed`
// value or `percentage`), and — on a valid pass — make the applicant
// eligible for the existing Final Interview. HR1 never stores HR3 exam
// content or answers and never auto-selects / auto-hires.
// =====================================================================

/** Generate an opaque attempt reference (Part 2 contract: attempt_ref). */
function examAttemptToken(): string
{
    return 'att_' . bin2hex(random_bytes(24));
}

/**
 * Resolve an assignment by its opaque access_token, enriched with the
 * applicant + exam + job so it can be validated. Returns null when the
 * token does not resolve.
 */
function findAssignmentByToken(string $token): ?array
{
    $stmt = db()->prepare(
        'SELECT ea.*, a.applicant_no, a.first_name, a.last_name, a.email,
                a.position_applied, a.status AS applicant_status, a.user_id AS applicant_user_id,
                e.title AS exam_title, e.passing_score, e.max_attempts, e.source_provider,
                e.instructions, e.exam_url, e.job_posting_id AS exam_job_id, e.status AS exam_status,
                j.title AS job_title, j.job_code
         FROM exam_assignments ea
         JOIN applicants a ON a.id = ea.applicant_id
         LEFT JOIN exams e ON e.id = ea.exam_id
         LEFT JOIN job_postings j ON j.id = ea.job_posting_id
         WHERE ea.access_token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/**
 * Begin an examination for an eligible assignment, identified ONLY by its
 * opaque access_token. This is the server-side action behind the access
 * page's "Start Examination" button.
 *
 * SECURITY
 *   - The token is the only credential accepted; no applicant id, email,
 *     job id or sequential DB id is ever used as authorization.
 *   - Only `assigned` assignments may be begun; already in-progress /
 *     completed / voided / expired assignments are rejected.
 *   - A valid launch records begun_at (first time only) and flips the
 *     assignment to `in_progress`.
 *   - Returns the provider (HR3) examination URL so HR1 can redirect the
 *     applicant to the exam-taking source. HR1 never embeds the HR3 URL in
 *     emails — the applicant always enters via this HR1 tokenized page.
 *
 * @param string $token The opaque assignment access_token (exm_...)
 * @return array{ok:bool, code:string, message:string, url?:string}
 */
function beginExamAssignment(string $token): array
{
    $assignment = findAssignmentByToken($token);
    if (!$assignment) {
        return ['ok' => false, 'code' => 'not_found', 'message' => 'Examination not found for the given link.'];
    }
    if ((string) $assignment['status'] !== 'assigned') {
        return ['ok' => false, 'code' => 'not_beginnable', 'message' => 'This examination is not available to begin.'];
    }
    if (empty($assignment['exam_url'])) {
        return ['ok' => false, 'code' => 'no_provider', 'message' => 'The examination source is not yet configured. Please contact HR.'];
    }

    $now = date('Y-m-d H:i:s');
    db()->prepare(
        "UPDATE exam_assignments
         SET status = 'in_progress',
             begun_at = IF(begun_at IS NULL, ?, begun_at)
         WHERE id = ? AND status = 'assigned'"
    )->execute([$now, (int) $assignment['id']]);

    securityLog(
        'exam_begun',
        "assignment_id={$assignment['id']} exam_id=" . var_export($assignment['exam_id'], true),
        (int) $assignment['applicant_user_id']
    );

    return ['ok' => true, 'code' => 'begun', 'message' => 'Examination started.', 'url' => $assignment['exam_url']];
}

/**
 * Server-side pass threshold: recompute the percentage from earned/total
 * and derive pass/fail against the exam's passing_score. The external
 * caller's `passed` / `percentage` values are NEVER trusted (score
 * manipulation protection).
 *
 * @return array{percentage:float, passed:bool}
 */
function examPassThreshold(float $earned, float $total, float $passingScore): array
{
    if ($total <= 0) {
        return ['percentage' => 0.0, 'passed' => false];
    }
    $percentage = round(($earned / $total) * 100, 2);
    $percentage = max(0.0, min(100.0, $percentage));
    return ['percentage' => $percentage, 'passed' => $percentage >= $passingScore];
}

/**
 * Whether an applicant is eligible for the FINAL INTERVIEW.
 *
 * Rule: the applicant has PASSED an examination on an active exam for their
 * applied Job/Position, and has not yet already progressed to (or past) the
 * interview stage. This is a server-computed flag — the applicant is NOT
 * automatically marked selected, hired, or moved to `interview`; the HR user
 * makes that decision by scheduling the Final Interview via the existing
 * Interview module.
 */
function applicantFinalInterviewEligible(array $applicant): bool
{
    $jobId = (int) ($applicant['job_posting_id'] ?? 0);
    $status = (string) ($applicant['status'] ?? '');
    if ($jobId <= 0) {
        return false;
    }
    if (in_array($status, ['new', 'screening', 'shortlisted', 'rejected', 'hired', 'offered', 'interview'], true)) {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT 1
         FROM exam_assignments ea
         JOIN exams e ON e.id = ea.exam_id AND e.job_posting_id = ? AND e.status = \'active\'
         JOIN exam_attempts at ON at.assignment_id = ea.id
         JOIN exam_results er ON er.attempt_id = at.id AND er.passed = 1
         WHERE ea.applicant_id = ?
         LIMIT 1'
    );
    $stmt->execute([$jobId, (int) $applicant['id']]);
    return (bool) $stmt->fetch();
}

/**
 * Record + validate an exam result on the server (shared by the API ingest
 * endpoint and the HR web form). This is the single hardened insertion path.
 *
 * Returns a structured result:
 *   [ok, message, code, attempt_id?, result_id?, percentage?, passed?, interview_eligible?]
 * code is a stable machine key used by the API to map to an HTTP status.
 *
 * Validation performed here:
 *   - assignment is not already finalised (completed/expired/voided)  -> replay
 *   - applicant still linked and applicant.job_posting_id == exam.job_posting_id
 *   - scores sane (total > 0, 0 <= earned <= total)
 *   - pass/fail derived server-side against exam.passing_score (score manipulation)
 *   - no result already recorded for this assignment (duplicate processing)
 *   - provider external_result_id is unique (replay of the same provider result)
 *   - attempt count respects the exam's max_attempts
 */
function recordExamResult(
    array $assignment,
    float $earned,
    float $total,
    ?string $externalResultId = null,
    ?string $attemptRef = null,
    ?string $scoredAt = null,
    string $receivedVia = 'api'
): array {
    if (in_array($assignment['status'] ?? '', ['completed', 'expired', 'voided'], true)) {
        return ['ok' => false, 'code' => 'assignment_finalised', 'message' => 'This exam assignment is already finalised and cannot be modified (result already processed or expired).'];
    }

    // A result can only be recorded against a concrete exam reference (which
    // carries the passing score). A "requested / awaiting HR3" assignment has
    // exam_id = NULL until the future external provider (or an HR1 exam
    // reference) is configured — it cannot be finalised yet.
    $assignmentExamId = (int) ($assignment['exam_id'] ?? 0);
    if ($assignmentExamId <= 0) {
        return ['ok' => false, 'code' => 'exam_not_configured', 'message' => 'This examination is still a request awaiting the external exam provider. A result can be recorded only once the examination reference is configured.'];
    }

    $examStmt = db()->prepare('SELECT * FROM exams WHERE id = ? LIMIT 1');
    $examStmt->execute([$assignmentExamId]);
    $exam = $examStmt->fetch();
    if (!$exam) {
        return ['ok' => false, 'code' => 'exam_not_found', 'message' => 'The examination could not be found.'];
    }
    if ($exam['status'] !== 'active') {
        return ['ok' => false, 'code' => 'exam_not_active', 'message' => 'The examination is not active and results cannot be recorded.'];
    }

    $aStmt = db()->prepare('SELECT * FROM applicants WHERE id = ? LIMIT 1');
    $aStmt->execute([(int) $assignment['applicant_id']]);
    $applicant = $aStmt->fetch();
    if (!$applicant) {
        return ['ok' => false, 'code' => 'applicant_not_found', 'message' => 'The applicant could not be found.'];
    }

    // Association integrity: the assignment links applicant -> exam -> job; re-verify
    // that the applied Job/Position still matches the exam's job (mismatch guard).
    if ((int) $assignment['job_posting_id'] !== (int) $exam['job_posting_id']
        || (int) $applicant['job_posting_id'] !== (int) $exam['job_posting_id']) {
        return ['ok' => false, 'code' => 'job_mismatch', 'message' => 'The result does not match the applicant\'s applied Job/Position for this examination.'];
    }

    // Score sanity.
    if ($total <= 0) {
        return ['ok' => false, 'code' => 'invalid_total', 'message' => 'Total points must be greater than zero.'];
    }
    if ($earned < 0 || $earned > $total) {
        return ['ok' => false, 'code' => 'invalid_score', 'message' => 'Earned points must be between 0 and the total points.'];
    }

    // Replay / duplicate: a result must not already be recorded on this assignment.
    $dupStmt = db()->prepare(
        'SELECT 1 FROM exam_attempts at JOIN exam_results er ON er.attempt_id = at.id
         WHERE at.assignment_id = ? LIMIT 1'
    );
    $dupStmt->execute([(int) $assignment['id']]);
    if ($dupStmt->fetch()) {
        return ['ok' => false, 'code' => 'duplicate_result', 'message' => 'A result for this exam assignment has already been recorded. Duplicate results are rejected.'];
    }

    // Replay: the provider's external_result_id must be unique system-wide.
    if ($externalResultId !== null && $externalResultId !== '') {
        $extStmt = db()->prepare('SELECT 1 FROM exam_results WHERE external_result_id = ? LIMIT 1');
        $extStmt->execute([$externalResultId]);
        if ($extStmt->fetch()) {
            return ['ok' => false, 'code' => 'duplicate_external', 'message' => 'This external result has already been processed. Replayed results are rejected.'];
        }
    }

    // Respect max_attempts (default 1 => a single recorded result per assignment).
    $cntStmt = db()->prepare('SELECT COUNT(*) c FROM exam_attempts WHERE assignment_id = ?');
    $cntStmt->execute([(int) $assignment['id']]);
    $attemptCount = (int) $cntStmt->fetch()['c'];
    $maxAttempts = max(1, (int) $exam['max_attempts']);
    if ($attemptCount >= $maxAttempts) {
        return ['ok' => false, 'code' => 'max_attempts', 'message' => 'The maximum number of attempts for this examination has been reached.'];
    }

    // Server-side pass threshold (never trust the external value).
    $threshold = examPassThreshold($earned, $total, (float) $exam['passing_score']);
    $percentage = $threshold['percentage'];
    $passed = $threshold['passed'];

    // Resolve the scored_at timestamp safely; otherwise use now.
    $ts = $scoredAt;
    if ($ts === null || $ts === '' || strtotime($ts) === false) {
        $ts = date('Y-m-d H:i:s');
    } else {
        $ts = date('Y-m-d H:i:s', strtotime($ts));
    }

    $attemptRef = ($attemptRef !== null && $attemptRef !== '') ? $attemptRef : examAttemptToken();
    try {
        db()->beginTransaction();

        db()->prepare(
            'INSERT INTO exam_attempts (assignment_id, attempt_number, external_ref, source_provider, started_at, submitted_at, status)
             VALUES (?,?,?,?,NULL,?,\'submitted\')'
        )->execute([(int) $assignment['id'], $attemptCount + 1, $attemptRef, $exam['source_provider'], $ts]);
        $attemptId = (int) db()->lastInsertId();

        db()->prepare(
            'INSERT INTO exam_results (attempt_id, earned_points, total_points, percentage, passed,
                                       external_result_id, result_status, received_via, scored_at)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $attemptId,
            $earned,
            $total,
            $percentage,
            $passed ? 1 : 0,
            ($externalResultId !== null && $externalResultId !== '') ? $externalResultId : null,
            $passed ? 'passed' : 'failed',
            $receivedVia,
            $ts,
        ]);
        $resultId = (int) db()->lastInsertId();

        db()->prepare(
            "UPDATE exam_assignments SET status = 'completed', completed_at = ? WHERE id = ?"
        )->execute([$ts, (int) $assignment['id']]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('recordExamResult failed: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'server_error', 'message' => 'The result could not be recorded.'];
    }

    $interviewEligible = $passed && applicantFinalInterviewEligible($applicant);

    securityLog(
        'exam_result_recorded',
        "assignment_id={$assignment['id']} attempt_id={$attemptId} result_id={$resultId} passed=" . ($passed ? 1 : 0) . " received_via={$receivedVia}",
        null
    );

    interpolateNotifyResult($applicant, $passed, $exam, (int) $attemptId);

    // Send the exam-passed email to the applicant when the result is a pass.
    // Guarded by exam_email_sent_at to prevent duplicates (same pattern as
    // acceptance_email_sent_at for the screening-passed email).
    if ($passed && empty($applicant['exam_email_sent_at'])) {
        require_once __DIR__ . '/mail.php';
        $applicantName = trim(($applicant['first_name'] ?? '') . ' ' . ($applicant['last_name'] ?? ''));
        $emailSent = sendExamPassedFinalInterviewEmail(
            $applicant['email'],
            $applicantName,
            $applicant['position_applied'] ?? ''
        );
        if ($emailSent) {
            db()->prepare('UPDATE applicants SET exam_email_sent_at = NOW() WHERE id = ?')
                ->execute([(int) $applicant['id']]);
            securityLog(
                'app_exam_email_sent',
                'applicant_id=' . $applicant['id'] . ' attempt_id=' . $attemptId,
                null
            );
        } else {
            securityLog(
                'app_exam_email_failed',
                'applicant_id=' . $applicant['id'] . ' attempt_id=' . $attemptId,
                null
            );
        }
    }

    return [
        'ok' => true,
        'code' => 'recorded',
        'message' => 'Examination result recorded (' . ($passed ? 'Passed' : 'Failed') . ').'
            . ($passed ? ' The applicant is now eligible for the final interview.' : ''),
        'attempt_id' => $attemptId,
        'result_id' => $resultId,
        'attempt_ref' => $attemptRef,
        'percentage' => $percentage,
        'passed' => $passed,
        'interview_eligible' => $interviewEligible,
    ];
}

/** Notify the applicant's user account about a recorded result (best-effort). */
function interpolateNotifyResult(array $applicant, bool $passed, array $exam, int $attemptId): void
{
    $userId = applicantUserId($applicant);
    if (!$userId) {
        return;
    }
    notifyUser(
        $userId,
        'Examination result',
        'Your ' . $exam['title'] . ' result has been recorded (' . ($passed ? 'Passed' : 'Failed') . ').'
            . ($passed ? ' You are now eligible to proceed to the final interview.' : ''),
        BASE_URL . '/modules/applicant/exams.php'
    );
}
