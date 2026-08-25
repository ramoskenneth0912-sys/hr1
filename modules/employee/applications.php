<?php
/**
 * REMOVED — My Applications is no longer part of the Employee ESS.
 * Application tracking lives in the Applicant portal and Admin/HR only.
 * Kept as a stub so old links redirect instead of 404ing.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

header('Location: ' . BASE_URL . '/index.php');
exit;
