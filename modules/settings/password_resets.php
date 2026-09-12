<?php
/**
 * PASSWORD RESET REQUESTS — HR approval page.
 *
 * Lists pending password-reset requests. HR can approve or reject.
 * Shows history of all processed requests.
 * Security: only HR/Manager roles can access; self-approval is blocked server-side.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security_headers.php';
require_once __DIR__ . '/../../includes/password_reset.php';
require_once __DIR__ . '/../../includes/mail.php';

requireLogin();
requireHRorManager();

$user = getCurrentUser();
$hrUserId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$message = '';
$messageType = '';

// ── Handle approve/reject actions ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $postAction = $_POST['action'] ?? '';
    $postId = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;

    if ($postId > 0 && in_array($postAction, ['approve', 'reject'], true)) {
        if ($postAction === 'approve') {
            $rawToken = approveResetRequest($postId, $hrUserId, $_SERVER['REMOTE_ADDR'] ?? null);
            if ($rawToken) {
                $stmt = db()->prepare(
                    'SELECT prr.user_id, u.email AS user_email,
                            COALESCE(TRIM(CONCAT(e.first_name, \' \', e.last_name)), u.username) AS employee_name
                     FROM password_reset_requests prr
                     JOIN users u ON u.id = prr.user_id
                     LEFT JOIN employees e ON u.employee_id = e.id
                     WHERE prr.id = ?'
                );
                $stmt->execute([$postId]);
                $req = $stmt->fetch();
                if ($req && $req['user_email']) {
                    $emailSent = sendResetApprovedToEmployee($req['user_email'], $rawToken, $req['employee_name'] ?? '');
                    if ($emailSent) {
                        $message = 'Request approved. Reset link sent to employee.';
                    } else {
                        $message = 'Request approved, but the email could not be sent. Check the server error log for details.';
                    }
                } else {
                    $message = 'Request approved, but the employee has no valid email address.';
                }
                $messageType = 'success';
            } else {
                $message = 'Could not approve request. It may have expired, been processed, or you attempted to approve your own request.';
                $messageType = 'error';
            }
        } else {
            if (rejectResetRequest($postId, $hrUserId, $_SERVER['REMOTE_ADDR'] ?? null)) {
                $message = 'Request rejected.';
                $messageType = 'success';
            } else {
                $message = 'Could not reject request. It may have already been processed.';
                $messageType = 'error';
            }
        }
    }
}

$pendingRequests = getPendingResetRequests();
$allRequests = getAllResetRequests(100);

function e_preset(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Requests — <?= e_preset(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tailwind.css">
    <style>
        .page-header { margin-bottom: 1.5rem; }
        .page-header h1 { font-size: 1.35rem; font-weight: 700; color: var(--text-dark); margin-bottom: .25rem; }
        .page-header p { font-size: .85rem; color: var(--muted); }
        .msg { padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: .85rem; }
        .msg-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .msg-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .section-title { font-size: 1.05rem; font-weight: 600; color: var(--text-dark); margin: 1.5rem 0 .75rem; }
        table.listing { width: 100%; border-collapse: collapse; font-size: .85rem; }
        table.listing th { text-align: left; padding: .6rem .75rem; background: #f8f9fa; border-bottom: 2px solid #e5e7eb; font-weight: 600; color: var(--text-dark); }
        table.listing td { padding: .6rem .75rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        table.listing tr:hover td { background: #fafafe; }
        .badge { display: inline-block; padding: .2rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-expired { background: #e5e7eb; color: #6b7280; }
        .btn-sm { padding: .35rem .75rem; font-size: .78rem; font-weight: 600; border-radius: 6px; border: none; cursor: pointer; transition: background .15s; }
        .btn-approve { background: #10b981; color: #fff; }
        .btn-approve:hover { background: #059669; }
        .btn-reject { background: #ef4444; color: #fff; }
        .btn-reject:hover { background: #dc2626; }
        .btn-disabled { opacity: .5; pointer-events: none; }
        .empty-msg { text-align: center; padding: 2rem; color: var(--muted); font-size: .9rem; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

<div class="main-content" style="padding: 2rem; max-width: 960px; margin: 0 auto;">
    <a href="<?= BASE_URL ?>/modules/settings/index.php" style="display:inline-flex; align-items:center; gap:.35rem; font-size:.85rem; font-weight:600; color:var(--text-dark); text-decoration:none; margin-bottom:1rem; transition:color .15s;">
        <span aria-hidden="true">&larr;</span> Back to Settings
    </a>
    <div class="page-header">
        <h1>Password Reset Requests</h1>
        <p>Review and approve or reject employee password-reset requests.</p>
    </div>

    <?php if ($message): ?>
        <div class="msg <?= $messageType === 'success' ? 'msg-success' : 'msg-error' ?>"><?= e_preset($message) ?></div>
    <?php endif; ?>

    <div class="tab-bar">
        <a href="#pending" class="tab-link active">Pending (<?= count($pendingRequests) ?>)</a>
        <a href="#history" class="tab-link">History</a>
    </div>

    <div id="pending">
        <div class="section-title">Pending Requests</div>
        <?php if (empty($pendingRequests)): ?>
            <div class="empty-msg">No pending password-reset requests.</div>
        <?php else: ?>
            <table class="listing">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Email (masked)</th>
                        <th>Requested</th>
                        <th>Expires</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingRequests as $req): ?>
                    <tr>
                        <td><?= e_preset($req['username']) ?></td>
                        <td><?= e_preset($req['masked_email']) ?></td>
                        <td><?= e_preset(date('M d, Y H:i', strtotime($req['created_at']))) ?></td>
                        <td><?= e_preset(date('M d, Y H:i', strtotime($req['expires_at']))) ?></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn-sm btn-approve" onclick="return confirm('Approve this reset request? A reset link will be sent to the employee.')">Approve</button>
                            </form>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn-sm btn-reject" onclick="return confirm('Reject this reset request?')">Reject</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div id="history">
        <div class="section-title">Request History</div>
        <?php if (empty($allRequests)): ?>
            <div class="empty-msg">No password-reset requests found.</div>
        <?php else: ?>
            <table class="listing">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Email (masked)</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th>Reviewed By</th>
                        <th>Reviewed At</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allRequests as $req):
                    $isExpired = ($req['status'] === 'pending' && strtotime($req['expires_at']) < time());
                    $status = $isExpired ? 'expired' : $req['status'];
                ?>
                    <tr>
                        <td><?= e_preset($req['username']) ?></td>
                        <td><?= e_preset($req['masked_email']) ?></td>
                        <td><span class="badge badge-<?= e_preset($status) ?>"><?= e_preset(ucfirst($status)) ?></span></td>
                        <td><?= e_preset(date('M d, Y H:i', strtotime($req['created_at']))) ?></td>
                        <td><?= e_preset($req['action_by_name'] ?? '—') ?></td>
                        <td><?= $req['action_at'] ? e_preset(date('M d, Y H:i', strtotime($req['action_at']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var pending = document.getElementById('pending');
    var history = document.getElementById('history');
    var tabs = document.querySelectorAll('.tab-bar a');
    function show(tab) {
        pending.style.display = tab === 'pending' ? '' : 'none';
        history.style.display = tab === 'history' ? '' : 'none';
        tabs.forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('href') === '#' + tab);
        });
    }
    function onHash() {
        var h = location.hash.replace('#', '');
        show(h === 'history' ? 'history' : 'pending');
    }
    window.addEventListener('hashchange', onHash);
    onHash();
})();
</script>
</body>
</html>
