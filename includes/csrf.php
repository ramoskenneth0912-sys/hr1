<?php
/**
 * Central CSRF protection.
 *
 * Every state-changing POST form must:
 *   1. render <?= csrf_field() ?> inside its <form method="post">, and
 *   2. call csrf_require() as the first statement of its POST handler.
 *
 * Tokens are 256-bit cryptographically secure random values bound to the
 * PHP session (never the URL), compared in constant time via hash_equals(),
 * and rotated whenever the session identity changes (login / password
 * change). SameSite=Lax on the session cookie is the second layer.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';

/** Get (or lazily create) this session's CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Force a fresh token — call after session_regenerate_id() / privilege changes. */
function csrf_rotate(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Hidden input to embed in every protected form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Constant-time check of a submitted token against the session value. */
function csrf_valid(?string $token): bool
{
    if ($token === null || $token === '' || strlen($token) > 128) {
        return false;
    }
    return hash_equals(csrf_token(), $token);
}

/**
 * Guard for POST handlers: validates the token and stops the request
 * (flash + redirect back to the same page) when missing or invalid.
 * No state change ever runs without a valid token.
 */
function csrf_require(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return; // only meaningful for POST requests
    }
    $sent = $_POST['csrf_token'] ?? null;
    if (!is_string($sent) || !csrf_valid($sent)) {
        flash('danger', 'Security check failed. The form was submitted from an unexpected context or your session expired. Please try again.');
        redirect($_SERVER['REQUEST_URI'] ?? BASE_URL . '/index.php');
    }
}
