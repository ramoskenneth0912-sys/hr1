<?php
/**
 * /api/v1/admin/* — aggregated administrative endpoints (hr/manager only).
 * Most logic delegates to JobsController / ApplicationsController /
 * UsersController; this class owns stats + applicant status transitions.
 */

declare(strict_types=1);

class AdminController
{
    /** GET /admin/applications/{id}/status → history-ish view. */
    public static function applicationStatus(int $id): never
    {
        Auth::requireAdmin();
        $app = ApplicationsController::findById($id);
        if (!$app) {
            Response::notFound('Application not found.');
        }
        Response::item([
            'id' => (int) $app['id'],
            'applicant_no' => $app['applicant_no'],
            'status' => $app['status'],
            'status_label' => Auth::statusDisplayMap()[$app['status']] ?? ucfirst($app['status']),
            'updated_at' => $app['updated_at'],
        ], 'Application status retrieved successfully.');
    }

    /**
     * PUT/PATCH /admin/applications/{id}/status
     * Body: { status: "shortlisted" | "reviewing" | ... , notes?: string }
     */
    public static function setApplicationStatus(int $id): never
    {
        Auth::requireAdmin();
        $app = ApplicationsController::findById($id);
        if (!$app) {
            Response::notFound('Application not found.');
        }

        $in = api_body();
        $norm = Auth::normalizeStatus(isset($in['status']) ? (string) $in['status'] : null);
        if ($norm === null) {
            Response::validation(['status' => 'Required. One of: pending/new, reviewing/screening, shortlisted, interview, accepted/offered, hired, rejected.']);
        }

        // Same stage-change gate as the web status.php: only transitions allowed
        // by the shared map are permitted (screening/interview are set exclusively
        // through their gated flows). Without this, the API could move an
        // applicant from 'new' straight to 'hired'.
        if (!canTransitionApplicationStatus((string) ($app['status'] ?? ''), $norm)) {
            Response::validation(['status' => 'This applicant cannot be moved to that status from their current stage.']);
        }

        db()->prepare('UPDATE applicants SET status = :s, updated_at = NOW() WHERE id = :id')
            ->execute([':s' => $norm, ':id' => $id]);

        notifyUser(
            applicantUserId($app),
            'Application update',
            'Your application for ' . ($app['job_title'] ?? (string) $app['position_applied'])
                . ' is now "' . (Auth::statusDisplayMap()[$norm] ?? $norm) . '".',
            BASE_URL . '/modules/applicant/dashboard.php'
        );

        if (array_key_exists('notes', $in)) {
            db()->prepare('UPDATE applicants SET notes = :n WHERE id = :id')
                ->execute([
                    ':n' => $in['notes'] === null ? null : mb_substr(trim((string) $in['notes']), 0, 4000),
                    ':id' => $id,
                ]);
        }

        $fresh = ApplicationsController::findById($id);
        Response::item([
            'id' => (int) $fresh['id'],
            'applicant_no' => $fresh['applicant_no'],
            'status' => $fresh['status'],
            'status_label' => Auth::statusDisplayMap()[$fresh['status']] ?? ucfirst($fresh['status']),
            'updated_at' => $fresh['updated_at'],
        ], 'Application status updated successfully.');
    }

    /** GET /admin/stats — dashboard counters for hr/manager. */
    public static function stats(): never
    {
        Auth::requireAdmin();
        $one = static function (string $sql): int {
            return (int) db()->query($sql)->fetch()['c'];
        };
        Response::item([
            'jobs' => [
                'total' => $one('SELECT COUNT(*) c FROM job_postings'),
                'open' => $one("SELECT COUNT(*) c FROM job_postings WHERE status = 'open'"),
                'closed' => $one("SELECT COUNT(*) c FROM job_postings WHERE status IN ('closed','filled')"),
            ],
            'applications' => [
                'total' => $one('SELECT COUNT(*) c FROM applicants'),
                'by_status' => self::countsByStatus(),
            ],
        ], 'Dashboard statistics retrieved successfully.');
    }

    private static function countsByStatus(): array
    {
        $rows = db()->query('SELECT status, COUNT(*) c FROM applicants GROUP BY status')->fetchAll();
        $display = Auth::statusDisplayMap();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['status']] = [
                'label' => $display[$r['status']] ?? ucfirst($r['status']),
                'count' => (int) $r['c'],
            ];
        }
        return $out;
    }
}
