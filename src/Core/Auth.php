<?php
declare(strict_types=1);

/**
 * Authentication + token handling.
 * Passwords: password_hash / password_verify (never plaintext).
 * API/mobile tokens: random 256-bit, only SHA-256 hash stored.
 * Reset/verify tokens: same, single-use, expiry enforced.
 */
final class Auth
{
    public const TYPE_API = 'api_session';
    public const TYPE_RESET = 'password_reset';
    public const TYPE_VERIFY = 'email_verify';

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /** @return array{token:string,hash:string} */
    public static function newToken(): array
    {
        $raw = bin2hex(random_bytes(32));
        return ['token' => $raw, 'hash' => hash('sha256', $raw)];
    }

    /**
     * Issue a token row. Returns raw token (show once).
     * @param array<string,mixed>|null $user
     */
    public static function issueToken(PDO $pdo, int $userId, string $type, int $ttlSeconds): string
    {
        $t = self::newToken();
        $exp = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $st = $pdo->prepare('INSERT INTO auth_tokens (user_id, token_hash, type, expires_at) VALUES (:u,:h,:t,:e)');
        $st->execute([':u' => $userId, ':h' => $t['hash'], ':t' => $type, ':e' => $exp]);
        return $t['token'];
    }

    /** @return array<string,mixed>|null user row if token valid */
    public static function userFromBearer(PDO $pdo, string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);
        $now = gmdate('Y-m-d H:i:s');
        $st = $pdo->prepare(
            "SELECT u.* FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :h AND t.type = 'api_session'
             AND t.used_at IS NULL AND t.expires_at > :now LIMIT 1"
        );
        $st->execute([':h' => $hash, ':now' => $now]);
        $user = $st->fetch();
        if (!$user) {
            return null;
        }
        if (($user['account_status'] ?? '') !== 'active') {
            return null;
        }
        return $user;
    }

    /**
     * Atomically consume a single-use token (password reset / email verify).
     * Uses a single conditional UPDATE so concurrent requests cannot both succeed:
     * only the first UPDATE matches (used_at IS NULL + not expired); others get rowCount 0.
     * @return array<string,mixed>|null token row as it was before consumption, or null
     */
    public static function consumeToken(PDO $pdo, string $rawToken, string $type): ?array
    {
        $hash = hash('sha256', $rawToken);
        $now = gmdate('Y-m-d H:i:s');
        $u = $pdo->prepare(
            'UPDATE auth_tokens SET used_at = :now
             WHERE token_hash = :h AND type = :t AND used_at IS NULL AND expires_at > :now2'
        );
        $u->execute([':now' => $now, ':h' => $hash, ':t' => $type, ':now2' => $now]);
        if ($u->rowCount() === 0) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM auth_tokens WHERE token_hash = :h AND type = :t LIMIT 1');
        $st->execute([':h' => $hash, ':t' => $type]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function revokeToken(PDO $pdo, string $rawToken): void
    {
        $hash = hash('sha256', $rawToken);
        $now = gmdate('Y-m-d H:i:s');
        $st = $pdo->prepare('UPDATE auth_tokens SET used_at = :now WHERE token_hash = :h AND used_at IS NULL');
        $st->execute([':now' => $now, ':h' => $hash]);
    }

    /** Revoke all active API sessions for a user (call after password reset/change). */
    public static function revokeUserApiSessions(PDO $pdo, int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $st = $pdo->prepare(
            "UPDATE auth_tokens SET used_at = :now
             WHERE user_id = :u AND type = 'api_session' AND used_at IS NULL"
        );
        $st->execute([':now' => $now, ':u' => $userId]);
    }

    public static function sessionLogin(int $userId): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        session_regenerate_id(true);
        $now = time();
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_at'] = $now;
        $_SESSION['last_activity'] = $now;
        $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Check server-side session expiry. Returns true when the session is still valid
     * (and slides last_activity); false when expired (caller must destroy/ignore it).
     */
    public static function sessionStillValid(): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        $now = time();
        $idleMinutes = Config::getInt('SESSION_IDLE_MINUTES', 120);
        $absoluteHours = Config::getInt('SESSION_ABSOLUTE_HOURS', 12);
        $loginAt = isset($_SESSION['login_at']) ? (int) $_SESSION['login_at'] : $now;
        $last = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : $loginAt;
        if ($idleMinutes > 0 && ($now - $last) > $idleMinutes * 60) {
            return false;
        }
        if ($absoluteHours > 0 && ($now - $loginAt) > $absoluteHours * 3600) {
            return false;
        }
        $_SESSION['last_activity'] = $now;
        return true;
    }

    public static function sessionLogout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** @return array<string> role slugs */
    public static function userRoles(PDO $pdo, int $userId): array
    {
        $st = $pdo->prepare('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :u');
        $st->execute([':u' => $userId]);
        return array_column($st->fetchAll(), 'slug');
    }

    /** @return array<string> permission slugs */
    public static function userPermissions(PDO $pdo, int $userId): array
    {
        $st = $pdo->prepare(
            'SELECT DISTINCT p.slug FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :u'
        );
        $st->execute([':u' => $userId]);
        return array_column($st->fetchAll(), 'slug');
    }
}
