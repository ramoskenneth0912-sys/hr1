<?php
/**
 * MY GOALS — Employee Portal ESS module.
 * Session-scoped, employee-only. The page shell is rendered by PHP; the
 * goals module itself is a React widget mounted below.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireNotApplicant();

$user = getCurrentUser();
$employeeId = $user['employee_id'] ?? null;

if (!$employeeId) {
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="page-header fade-in-up"><div><h1 class="page-title">My Goals</h1></div></div>';
    echo '<div class="alert alert-warning">No employee profile linked to your account. Please contact HR.</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pageTitle = 'My Goals';
$currentModule = 'my-goals';
$bodyClass = 'page-dashboard';
$reactEntry = 'main';

$hr1User = [
    'employee_id' => $employeeId,
    'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
];
require_once __DIR__ . '/../../includes/header.php';
?>
<div
    data-react-widget="my-goals"
    data-hr1-user="<?= htmlspecialchars(json_encode($hr1User, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"
    class="space-y-6"
></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>