<?php
/**
 * Legacy alias — My Account was renamed to My Profile.
 * Kept only so existing links keep working; no content lives here.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

header('Location: ' . BASE_URL . '/modules/employee/profile.php');
exit;
