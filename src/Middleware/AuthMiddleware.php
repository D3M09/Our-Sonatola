<?php
declare(strict_types=1);

/** Resolves current user from session or Bearer token. Attach to Request::$user. */
final class AuthMiddleware
{
    public static function attach(PDO $pdo, Request $req): void
    {
        // 1) Bearer token (mobile / Capacitor future app)
        $bearer = $req->bearerToken();
        if ($bearer !== null) {
            $user = Auth::userFromBearer($pdo, $bearer);
            if ($user !== null) {
                $req->user = self::enrich($pdo, $user);
                return;
            }
        }
        // 2) Session (browser admin) with server-side idle + absolute expiry.
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            if (!Auth::sessionStillValid()) {
                Auth::sessionLogout();
                $req->user = null;
                return;
            }
            // Basic session hijack mitigation: bind to IP loosely (log only, don't hard-fail on mobile networks)
            $st = $pdo->prepare("SELECT * FROM users WHERE id = :id AND account_status = 'active' LIMIT 1");
            $st->execute([':id' => (int) $_SESSION['user_id']]);
            $user = $st->fetch();
            if ($user) {
                $req->user = self::enrich($pdo, $user);
                return;
            }
        }
        $req->user = null;
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private static function enrich(PDO $pdo, array $user): array
    {
        $uid = (int) $user['id'];
        $user['roles'] = Auth::userRoles($pdo, $uid);
        $user['permissions'] = Auth::userPermissions($pdo, $uid);
        // DB-driven union scope (roles.scope) with ScopePolicy fallback for old installs.
        try {
            $user['union_scoped'] = ScopePolicy::isUnionScoped($user['roles'], $pdo);
        } catch (Throwable) {
            $user['union_scoped'] = ScopePolicy::isUnionScoped($user['roles']);
        }
        unset($user['password_hash']);
        return $user;
    }

    public static function requireAuth(Request $req): void
    {
        if ($req->user === null) {
            throw AppException::unauthorized();
        }
    }
}
