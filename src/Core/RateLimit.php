<?php
declare(strict_types=1);

/**
 * File/session-backed rate limiter.
 * Uses sys temp dir; on production with multiple nodes replace with DB table.
 */
final class RateLimit
{
    public static function hit(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $file = sys_get_temp_dir() . '/sonatola_rl_' . sha1($key) . '.json';
        $now = time();
        $bucket = ['count' => 0, 'reset' => $now + $windowSeconds];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $d = $raw ? json_decode($raw, true) : null;
            if (is_array($d) && isset($d['reset']) && $d['reset'] > $now) {
                $bucket = $d;
            }
        }
        $bucket['count'] = (int) ($bucket['count'] ?? 0) + 1;
        @file_put_contents($file, json_encode($bucket), LOCK_EX);
        return $bucket['count'] <= $maxAttempts;
    }

    public static function check(string $key, int $maxAttempts, int $windowSeconds): void
    {
        if (!self::hit($key, $maxAttempts, $windowSeconds)) {
            throw AppException::rateLimited('Too many requests. Please try again later.');
        }
    }

    public static function clear(string $key): void
    {
        $file = sys_get_temp_dir() . '/sonatola_rl_' . sha1($key) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
