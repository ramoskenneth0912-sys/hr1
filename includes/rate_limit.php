<?php
/**
 * File-based rate limiter for the web login surface.
 * Limits: max $maxAttempts attempts per $windowSeconds per key.
 *
 * Rate limit files stored in storage/web_rate_limits/ (blocked by .htaccess).
 */

function webRateLimitDir(): string
{
    $dir = dirname(__DIR__) . '/storage/web_rate_limits';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function webRateLimitFile(string $key): string
{
    return webRateLimitDir() . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.json';
}

function webRateLimit(string $key, int $maxAttempts = 5, int $windowSeconds = 900): bool
{
    $file = webRateLimitFile($key);
    $now = time();

    $data = ['attempts' => [], 'blocked_until' => 0];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
    }

    if (($data['blocked_until'] ?? 0) > $now) {
        return false;
    }

    $data['attempts'] = array_values(array_filter($data['attempts'] ?? [], function ($ts) use ($now, $windowSeconds) {
        return ($now - $ts) < $windowSeconds;
    }));

    if (count($data['attempts']) >= $maxAttempts) {
        $data['blocked_until'] = $now + $windowSeconds;
        file_put_contents($file, json_encode($data));
        return false;
    }

    return true;
}

function webRateLimitRecord(string $key): void
{
    $file = webRateLimitFile($key);
    $now = time();

    $data = ['attempts' => [], 'blocked_until' => 0];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
    }

    $data['attempts'][] = $now;
    file_put_contents($file, json_encode($data));
}

function webRateLimitReset(string $key): void
{
    $file = webRateLimitFile($key);
    if (file_exists($file)) {
        @unlink($file);
    }
}
