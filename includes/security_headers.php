<?php
/**
 * Security headers applied to all HTTP responses.
 * Included by header.php (main layout) and standalone pages.
 *
 * CSP notes:
 *  - The app ships inline <script> blocks (footer.php, login.php, jobs.php,
 *    browse-jobs.php, password_resets.php, 404.php) and inline event handlers
 *    (e.g. documents.php/password_resets.php), plus Vue/React Vite dev-server
 *    bundles on localhost:5173 / 5174 during development. These are accounted
 *    for (script-src 'unsafe-inline' + the two dev servers) so the policy does
 *    not break the existing application. Real XSS hardening that does not
 *    conflict with the app is still enforced via object-src 'none', base-uri,
 *    form-action, frame-ancestors, img-src and connect-src tightening.
 *  - style-src allows inline styles (the UI relies on <style> blocks and
 *    inline style="" attributes throughout) plus the Google Fonts stylesheet.
 *  - font-src allows the Google Fonts CDN (fonts.gstatic.com).
 *
 * HSTS notes:
 *  - This environment is local XAMPP over plain HTTP. Browsers require HTTPS
 *    before honoring HSTS, so sending it here is both ineffective and, if it
 *    were honored, could lock localhost into HTTPS. Therefore HSTS (and
 *    upgrade-insecure-requests) are emitted ONLY when the request is already
 *    served over HTTPS — i.e. production — and never on local HTTP.
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Detect HTTPS exactly as session.php does, so header behaviour matches the
// session cookie hardening (Secure flag) that is already in place.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

$csp = "default-src 'self'; "
     . "script-src 'self' 'unsafe-inline' http://localhost:5173 http://localhost:5174; "
     . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
     . "font-src 'self' https://fonts.gstatic.com data:; "
     . "img-src 'self' data:; "
     . "connect-src 'self' http://localhost:5173 http://localhost:5174; "
     . "object-src 'none'; "
     . "base-uri 'self'; "
     . "frame-ancestors 'self'; "
     . "form-action 'self'";

// Only upgrade to HTTPS when the page is already served over HTTPS. Forcing it
// on plain HTTP would break the local development setup.
if ($isHttps) {
    $csp .= '; upgrade-insecure-requests';
}
header("Content-Security-Policy: " . $csp);

// HSTS is HTTPS-only. Never sent over plain HTTP (local development).
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
