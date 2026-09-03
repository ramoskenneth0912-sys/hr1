<?php
/**
 * /api/v1/exams — HR3 → HR1 examination provisioning boundary.
 *
 * This is the HR1-side endpoint that the future HR3 provider calls to
 * provide/deliver an examination reference to HR1. HR1 stores and manages
 * the examination in the existing `exams` table. HR3 NEVER emails the
 * applicant directly; HR1 owns all applicant communication.
 *
 *   POST /exams           -> HR3 provides a new examination to HR1
 *                            (creates an `exams` row, source_provider=HR3)
 *   GET  /exams           -> list examinations available in HR1
 *
 * AUTHENTICATION
 *   - API key (X-API-Key) with scope `exams:write` for POST (system-to-system)
 *     OR a Bearer/session hr|manager user.
 *   - `exams:read` for GET.
 *
 * SECURITY
 *   - External exam reference (external_ref) UNIQUE — duplicate imports are
 *     rejected server-side (idempotency / replay protection).
 *   - HR3 can only provide examinations. It CANNOT create/modify applicants,
 *     send emails, assign exams to applicants, or change recruitment status.
 *   - All fields validated; unknown/unauthorized input rejected.
 */

declare(strict_types=1);

require_once BASE_PATH . '/includes/exam.php';

class ExamProvisioningController
{
    /**
     * POST /exams — receive an examination provided by HR3.
     * @param bool $isUpdate When true, update an existing exam by external_ref.
     */
    public static function provision(bool $isUpdate = false): never
    {
        Auth::requireAdmin(true);
        Auth::requireScope('exams:write');

        $in = api_body();

        // --- Validate required fields ---
        $title  = trim((string) ($in['title'] ?? ''));
        $ref    = trim((string) ($in['reference'] ?? ($in['external_ref'] ?? '')));
        $jobRef = trim((string) ($in['job_posting_ref'] ?? ''));
        $passingScore = isset($in['passing_score']) ? (float) $in['passing_score'] : 60.0;
        $instructions = trim((string) ($in['instructions'] ?? ''));
        $description  = trim((string) ($in['description'] ?? ''));
        $timeLimit    = isset($in['time_limit_minutes']) ? (int) $in['time_limit_minutes'] : 30;
        $maxAttempts  = isset($in['max_attempts']) ? (int) $in['max_attempts'] : 1;
        $examUrl      = trim((string) ($in['exam_url'] ?? ''));

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Examination title is required.';
        }
        if ($ref === '') {
            $errors['reference'] = 'An external examination reference is required for deduplication.';
        }
        if (strlen($title) > 200) {
            $errors['title'] = 'Title exceeds 200 characters.';
        }
        if (strlen($ref) > 120) {
            $errors['reference'] = 'Reference exceeds 120 characters.';
        }
        if ($passingScore <= 0 || $passingScore > 100) {
            $errors['passing_score'] = 'Passing score must be between 0 and 100.';
        }
        if ($timeLimit <= 0 || $timeLimit > 720) {
            $errors['time_limit_minutes'] = 'Time limit must be between 1 and 720 minutes.';
        }
        if ($maxAttempts <= 0 || $maxAttempts > 10) {
            $errors['max_attempts'] = 'Max attempts must be between 1 and 10.';
        }
        if ($examUrl !== '') {
            $urlOk = filter_var($examUrl, FILTER_VALIDATE_URL) !== false;
            $parsed = parse_url($examUrl);
            $isHttps = $urlOk && isset($parsed['scheme']) && strtolower((string) $parsed['scheme']) === 'https';
            if (!$urlOk || !$isHttps || strlen($examUrl) > 500) {
                $errors['exam_url'] = 'The examination URL must be a valid HTTPS URL (max 500 characters).';
            }
        }
        if ($errors) {
            Response::validation($errors);
        }

        // --- Resolve the job posting by ref (job_code, applicant_no, or numeric id) ---
        $jobPostingId = null;
        if ($jobRef !== '') {
            $jobQuery = db()->prepare(
                "SELECT id FROM job_postings
                 WHERE job_code = ? OR title = ? OR id = ?
                 LIMIT 1"
            );
            $jobQuery->execute([$jobRef, $jobRef, is_numeric($jobRef) ? (int) $jobRef : -1]);
            $job = $jobQuery->fetch();
            if (!$job) {
                Response::validation(['job_posting_ref' => 'The referenced job posting could not be found.']);
            }
            $jobPostingId = (int) $job['id'];
        }

        // --- Check for duplicate by external_ref ---
        $dupe = db()->prepare('SELECT id FROM exams WHERE external_ref = ? LIMIT 1');
        $dupe->execute([$ref]);
        $existing = $dupe->fetch();

        if ($existing && !$isUpdate) {
            Response::conflict(
                'An examination with this external reference already exists.',
                ['external_ref' => $ref]
            );
        }

        try {
            if ($existing && $isUpdate) {
                db()->prepare(
                    'UPDATE exams
                     SET title = ?, job_posting_id = ?, description = ?, instructions = ?, exam_url = ?,
                         passing_score = ?, time_limit_minutes = ?, max_attempts = ?,
                         status = ?, provisioned_at = NOW()
                     WHERE id = ?'
                )->execute([
                    $title,
                    $jobPostingId,
                    $description !== '' ? $description : null,
                    $instructions !== '' ? $instructions : null,
                    $examUrl !== '' ? $examUrl : null,
                    $passingScore,
                    $timeLimit,
                    $maxAttempts,
                    'active',
                    (int) $existing['id'],
                ]);
                $examId = (int) $existing['id'];
                $created = false;
            } else {
                db()->prepare(
                    'INSERT INTO exams
                     (title, job_posting_id, description, instructions, exam_url, passing_score,
                      time_limit_minutes, max_attempts, source_provider, external_ref,
                      status, created_by, provisioned_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                )->execute([
                    $title,
                    $jobPostingId,
                    $description !== '' ? $description : null,
                    $instructions !== '' ? $instructions : null,
                    $examUrl !== '' ? $examUrl : null,
                    $passingScore,
                    $timeLimit,
                    $maxAttempts,
                    'HR3',
                    $ref,
                    'active',
                    Auth::id(),
                ]);
                $examId = (int) db()->lastInsertId();
                $created = true;
            }
        } catch (Throwable $e) {
            error_log('ExamProvisioningController::provision failed: ' . $e->getMessage());
            Response::serverError('Failed to register the examination.');
        }

        securityLog(
            $created ? 'exam_provisioned' : 'exam_provisioned_updated',
            "exam_id={$examId} external_ref={$ref}",
            Auth::id()
        );

        Response::created([
            'id'            => $examId,
            'title'         => $title,
            'reference'     => $ref,
            'source'        => 'HR3',
            'job_posting_id'=> $jobPostingId,
            'status'        => 'active',
            'created'       => $created,
        ], $created ? 'Examination received from HR3 and registered in HR1.' : 'Examination updated.');
    }

    /**
     * GET /exams — list examinations available in HR1 (HR/Admin view).
     * @param array $exams Pre-loaded exam rows (optional).
     */
    public static function list(array $exams = []): never
    {
        Auth::requireAdmin(true);
        Auth::requireScope('exams:read');

        if (empty($exams)) {
            $exams = db()->query(
                "SELECT e.id, e.title, e.source_provider, e.external_ref, e.status,
                        e.passing_score, e.time_limit_minutes, e.max_attempts,
                        e.provisioned_at, e.created_at,
                        j.title AS job_title, j.job_code
                 FROM exams e
                 LEFT JOIN job_postings j ON j.id = e.job_posting_id
                 ORDER BY e.created_at DESC"
            )->fetchAll();
        }

        foreach ($exams as &$row) {
            $row['id'] = (int) $row['id'];
            $row['passing_score'] = (float) $row['passing_score'];
            $row['time_limit_minutes'] = (int) $row['time_limit_minutes'];
            $row['max_attempts'] = (int) $row['max_attempts'];
        }
        unset($row);

        Response::item(['exams' => $exams], 'Examinations retrieved.');
    }

    /**
     * GET /exams/{id} — retrieve a single exam by internal id.
     */
    public static function show(int $examId): never
    {
        Auth::requireAdmin(true);
        Auth::requireScope('exams:read');

        $stmt = db()->prepare(
            "SELECT e.*, j.title AS job_title, j.job_code
             FROM exams e
             LEFT JOIN job_postings j ON j.id = e.job_posting_id
             WHERE e.id = ? LIMIT 1"
        );
        $stmt->execute([$examId]);
        $exam = $stmt->fetch();
        if (!$exam) {
            Response::notFound('Examination not found.');
        }
        $exam['id'] = (int) $exam['id'];
        $exam['passing_score'] = (float) $exam['passing_score'];
        $exam['time_limit_minutes'] = (int) $exam['time_limit_minutes'];
        $exam['max_attempts'] = (int) $exam['max_attempts'];

        Response::item($exam, 'Examination retrieved.');
    }
}
