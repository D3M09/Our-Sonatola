<?php
declare(strict_types=1);

/** CSRF protection for state-changing browser (session cookie) requests. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    public static function validate(?string $supplied): bool
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        if ($expected === '' || $supplied === null || $supplied === '') {
            return false;
        }
        return hash_equals((string) $expected, $supplied);
    }

    /** Checks header X-CSRF-Token or _csrf body field; throws 403 on failure for session authed writes. */
    public static function requireValid(Request $req): void
    {
        // Only enforce when request is session-authenticated (has session user) and is state-changing
        if (!in_array($req->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        if (empty($_SESSION['user_id'])) {
            return; // token-based API clients are exempt
        }
        $supplied = $req->headers['x-csrf-token'] ?? ($req->body['_csrf'] ?? null);
        if (!self::validate(is_string($supplied) ? $supplied : null)) {
            throw AppException::forbidden('Invalid CSRF token.');
        }
    }
}
