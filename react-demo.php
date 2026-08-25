<?php
/**
 * React integration demo — proves the PHP + React island pipeline works
 * end-to-end without touching any existing page or functionality.
 *
 * Pipeline: React (this page) → fetch /HR1/api/v1/jobs (PHP REST API,
 * session-aware) → MySQL. React never talks to the database directly.
 */
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/react.php';

$reactEntry = 'main';
$currentUser = getCurrentUser();
$pageTitle = 'React Integration Demo';
?>
        <div class="page-header">
            <div>
                <h1 class="page-title">React Integration Demo</h1>
                <p class="text-muted" style="margin:.25rem 0 0;">
                    Standalone sandbox page — your existing dashboards and pages are untouched.
                    <?php if ($currentUser): ?>
                        Logged in as <strong><?= e($currentUser['username'] ?? 'user') ?></strong> (role: <?= e(ucfirst(getUserRole())) ?>) via the existing PHP session.
                    <?php else: ?>
                        Viewing as guest — <a href="<?= BASE_URL ?>/auth/login.php">log in</a> to see session-aware API behavior.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div style="display:grid; gap:1rem; max-width:640px;">
            <div data-react-widget="open-positions"></div>
        </div>

<?php require_once __DIR__ . '/includes/footer.php';
