<?php
/**
 * Lightweight file-based rate limiter.
 * Fixed-window counters under /storage/api/rate_limits (never web-accessed logic).
 */

declare(strict_types=1);

class RateLimit
{
    /** Returns true when allowed; increments the window counter otherwise false. */
    public static function attempt(string $key, int $max, int $windowSeconds): bool
    {
        $file = API_RATE_DIR . '/' . hash('sha256', $key) . '.json';
        $now = time();
        $fh = @fopen($file, 'c+');
        if (!$fh) {
            return true; // fail-open: storage issues must not take the API down
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($state) || !isset($state['start'], $state['count'])
                || !is_int($state['start']) || ($now - $state['start']) >= $windowSeconds) {
                $state = ['start' => $now, 'count' => 0];
            }
            $state['count']++;
            if ($state['count'] > $max) {
                return false; // still persist state on unlock below
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state));
            return true;
        } finally {
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
