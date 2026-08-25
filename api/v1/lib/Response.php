<?php
/**
 * Standard JSON response envelope for the HR1 API.
 * Every response: {success, message, data?, meta?, errors?}
 */

declare(strict_types=1);

class Response
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS
        | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($payload, self::JSON_FLAGS);
        exit;
    }

    /** List response with pagination meta. */
    public static function list(array $data, string $message, int $page, int $limit, int $total): never
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'meta'    => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => (int) max(1, ceil($total / max(1, $limit))),
            ],
        ], 200);
    }

    public static function item(array $data, string $message, int $status = 200): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    public static function created(array $data, string $message): never
    {
        self::item($data, $message, 201);
    }

    /** 204 — no body at all. */
    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    public static function error(string $message, array $errors = [], int $status = 400): never
    {
        self::json(['success' => false, 'message' => $message, 'errors' => $errors ?: new stdClass()], $status);
    }

    // ---- Convenience wrappers -------------------------------------------

    public static function badRequest(string $msg = 'Invalid request', array $errors = []): never
    {
        self::error($msg, $errors, 400);
    }

    public static function unauthorized(string $msg = 'Authentication required.'): never
    {
        header('WWW-Authenticate: Bearer realm="HR1 API"');
        self::error($msg, [], 401);
    }

    public static function forbidden(string $msg = 'Access denied.'): never
    {
        self::error($msg, [], 403);
    }

    public static function notFound(string $msg = 'Resource not found.'): never
    {
        self::error($msg, [], 404);
    }

    public static function conflict(string $msg, array $errors = []): never
    {
        self::error($msg, $errors, 409);
    }

    public static function validation(array $errors, string $msg = 'Validation failed.'): never
    {
        self::error($msg, $errors, 422);
    }

    public static function tooManyRequests(string $msg = 'Too many requests.'): never
    {
        self::error($msg, [], 429);
    }

    public static function serverError(string $msg = 'Internal server error.'): never
    {
        self::error($msg, [], 500);
    }
}
