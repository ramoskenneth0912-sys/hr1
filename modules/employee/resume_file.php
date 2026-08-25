<?php
/**
 * REMOVED — resume viewing belonged to the employee My Applications page,
 * which is no longer part of the Employee ESS. Kept as a stub so old
 * links redirect instead of 404ing.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

header('Location: ' . BASE_URL . '/index.php');
exit;
