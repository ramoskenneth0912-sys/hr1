<?php
/**
 * Central session bootstrap — include this INSTEAD of calling
 * session_start() directly. Applies hardening once per request:
 * strict mode, HttpOnly cookie, SameSite=Lax, Secure when HTTPS.
 * Same-site behavior (logins, form posts, navigation) is unchanged.
 */

require_once __DIR__ . '/environment.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $isHttps = hr1_is_https();

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // Session inactivity timeout: 30 minutes
    $timeout = 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
    }
    $_SESSION['last_activity'] = time();
}
