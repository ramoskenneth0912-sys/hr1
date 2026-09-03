<?php
/**
 * /api/v1/exam-results — external examination result ingestion (HR3 boundary).
 *
 * This is the HR1-side endpoint the future HR3 provider calls to deliver an
 * examination outcome. HR1 owns the result ledger and validates everything
 * server-side:
 *
 *   POST /exam-results          -> ingest a result (records attempt+result,
 *                                  derives pass/fail server-side; marks the
 *                                  applicant eligible for the Final Interview
 *                                  on a valid pass). Does NOT auto-select/hire.
 *   GET  /exam-results?access_token=...  -> read the recorded result + stage.
 *
 * AUTHENTICATION
 *   - API key (X-API-Key) with scope `exams:results:write` (system-to-system,
 *     the intended HR3 path) OR a Bearer/session hr|manager user.
 *   - The external caller authenticates by the assignment's opaque
 *     `access_token` (exm_...). Raw database ids are NEVER accepted.
 *
 * SECURITY
 *   - applicant / job / exam / attempt association re-validated server-side
 *   - pass/fail derived from earned/total vs the exam's passing_score
 *     (external `passed` / `percentage` values are never trusted)
 *   - duplicate / replay rejected (finalised assignment, existing result,
 *     unique provider external_result_id)
 *   - score bounds enforced (0 <= earned <= total, total > 0)
 *   - rate limited + audited via the existing API security stack
 */

declare(strict_types=1);

require_once BASE_PATH . '/includes/exam.php';

class ExamResultsController
{
    /** POST /exam-results — ingest an external examination result. */
    public static function ingest(): never
    {
        Auth::requireAdmin(true);
        Auth::requireScope('exams:results:write');

        $in = api_body();
        $token = trim((string) ($in['access_token'] ?? ''));
        if ($token === '') {
            Response::validation(['access_token' => 'Required: the examination assignment reference (exm_...) issued by HR1.']);
        }

        $assignment = findAssignmentByToken($token);
        if (!$assignment) {
            Response::notFound('Examination assignment not found for the given reference.');
        }

        $earned = isset($in['earned_points']) ? (float) $in['earned_points'] : 0.0;
        $total  = isset($in['total_points']) ? (float) $in['total_points'] : 0.0;
        $externalResultId = isset($in['external_result_id']) ? trim((string) $in['external_result_id']) : null;
        $attemptRef = isset($in['attempt_ref']) ? trim((string) $in['attempt_ref']) : null;
        $scoredAt = isset($in['scored_at']) ? trim((string) $in['scored_at']) : null;

        $out = recordExamResult(
            $assignment,
            $earned,
            $total,
            $externalResultId !== '' ? $externalResultId : null,
            $attemptRef !== '' ? $attemptRef : null,
            $scoredAt !== '' ? $scoredAt : null,
            'api'
        );

        if (!$out['ok']) {
            $status = self::statusForCode($out['code']);
            Response::error(
                $out['message'],
                self::errorsForCode($out['code'], $out),
                $status
            );
        }

        Response::created([
            'assignment_ref' => $assignment['access_token'],
            'attempt_ref' => $out['attempt_ref'] ?? null,
            'result_id' => $out['result_id'] ?? null,
            'applicant_no' => $assignment['applicant_no'],
            'job' => [
                'job_code' => $assignment['job_code'],
                'title' => $assignment['job_title'],
            ],
            'exam' => [
                'title' => $assignment['exam_title'],
                'source_provider' => $assignment['source_provider'],
            ],
            'percentage' => $out['percentage'],
            'passed' => $out['passed'],
            'interview_eligible' => $out['interview_eligible'],
        ], 'Examination result recorded.');
    }

    /** GET /exam-results?access_token=... — read the recorded result + stage. */
    public static function show(): never
    {
        Auth::requireAdmin(true);
        Auth::requireScope('exams:read');

        $token = trim((string) (api_query('access_token') ?? ''));
        if ($token === '') {
            Response::validation(['access_token' => 'Required: the examination assignment reference (exm_...).']);
        }
        $assignment = findAssignmentByToken($token);
        if (!$assignment) {
            Response::notFound('Examination assignment not found for the given reference.');
        }

        $row = db()->prepare(
            'SELECT er.id, er.earned_points, er.total_points, er.percentage, er.passed,
                    er.result_status, er.received_via, er.scored_at, er.external_result_id,
                    at.external_ref AS attempt_ref, at.attempt_number, at.submitted_at
             FROM exam_attempts at
             LEFT JOIN exam_results er ON er.attempt_id = at.id
             WHERE at.assignment_id = ?
             ORDER BY at.attempt_number DESC LIMIT 1'
        );
        $row->execute([(int) $assignment['id']]);
        $result = $row->fetch();

        if (!$result || $result['id'] === null) {
            Response::item([
                'assignment_ref' => $assignment['access_token'],
                'applicant_no' => $assignment['applicant_no'],
                'recorded' => false,
            ], 'No result recorded yet for this examination.');
        }

        $applicant = db()->prepare('SELECT * FROM applicants WHERE id = ?');
        $applicant->execute([(int) $assignment['applicant_id']]);
        $appRow = $applicant->fetch() ?: [];

        Response::item([
            'assignment_ref' => $assignment['access_token'],
            'attempt_ref' => $result['attempt_ref'],
            'result_id' => (int) $result['id'],
            'applicant_no' => $assignment['applicant_no'],
            'applicant_status' => $assignment['applicant_status'],
            'job' => ['job_code' => $assignment['job_code'], 'title' => $assignment['job_title']],
            'exam' => ['title' => $assignment['exam_title'], 'source_provider' => $assignment['source_provider']],
            'recorded' => true,
            'score' => [
                'earned_points' => (float) $result['earned_points'],
                'total_points' => (float) $result['total_points'],
                'percentage' => (float) $result['percentage'],
                'passed' => (bool) $result['passed'],
                'result_status' => $result['result_status'],
                'received_via' => $result['received_via'],
                'scored_at' => $result['scored_at'],
            ],
            'interview_eligible' => applicantFinalInterviewEligible($appRow),
        ], 'Examination result retrieved successfully.');
    }

    private static function statusForCode(string $code): int
    {
        return match ($code) {
            'assignment_finalised', 'duplicate_result', 'duplicate_external' => 409,
            'server_error' => 500,
            default => 422,
        };
    }

    private static function errorsForCode(string $code, array $out): array
    {
        return ['code' => $code] + (isset($out['attempt_ref']) ? ['attempt_ref' => $out['attempt_ref']] : []);
    }
}
