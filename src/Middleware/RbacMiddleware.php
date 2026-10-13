<?php
declare(strict_types=1);

/**
 * RBAC + union/upazila data scoping.
 * NEVER rely on frontend. Every protected controller must call these.
 */
final class RbacMiddleware
{
    public static function requireRole(Request $req, string ...$roles): void
    {
        AuthMiddleware::requireAuth($req);
        $have = $req->user['roles'] ?? [];
        foreach ($roles as $r) {
            if (in_array($r, $have, true)) {
                return;
            }
        }
        throw AppException::forbidden('Requires role: ' . implode(',', $roles));
    }

    public static function requirePermission(Request $req, string ...$perms): void
    {
        AuthMiddleware::requireAuth($req);
        // Super admin bypasses permission checks but is still scoped by code paths that need it
        if (in_array('super_admin', $req->user['roles'] ?? [], true)) {
            return;
        }
        $have = $req->user['permissions'] ?? [];
        foreach ($perms as $p) {
            if (in_array($p, $have, true)) {
                return;
            }
        }
        throw AppException::forbidden('Missing permission: ' . implode(',', $perms));
    }

    public static function isSuperAdmin(Request $req): bool
    {
        return in_array('super_admin', $req->user['roles'] ?? [], true);
    }

    /**
     * Enforce union-level isolation.
     * Union-scoped roles (see ScopePolicy) may only touch rows of their own union.
     * Returns the union_id the caller is allowed to operate on, or null for global roles.
     */
    public static function allowedUnionId(Request $req): ?int
    {
        if (self::isSuperAdmin($req)) {
            return null; // unrestricted
        }
        $roles = $req->user['roles'] ?? [];
        // Prefer DB-driven flag set at auth time; fall back to ScopePolicy map.
        $scoped = $req->user['union_scoped'] ?? ScopePolicy::isUnionScoped($roles);
        if (!$scoped) {
            return null; // global roles (upazila_admin, editor, citizen, ...) see all
        }
        $u = $req->user['union_id'] ?? null;
        if ($u === null || $u === '') {
            throw AppException::forbidden('No union assigned to this account.');
        }
        if (is_int($u)) {
            return $u;
        }
        if (is_string($u) && preg_match('/^-?\d+$/', trim($u))) {
            return (int) trim($u);
        }
        throw AppException::forbidden('Invalid union scope.');
    }

    /**
     * Assert a row belongs to the caller's scope. Call after fetching row.
     * Uses strict integer validation so "3 OR 1=1" style tampering never casts to 3.
     * @param array<string,mixed> $row must contain union_id when applicable
     */
    public static function assertUnionAccess(Request $req, array $row): void
    {
        $allowed = self::allowedUnionId($req);
        if ($allowed === null) {
            return;
        }
        if (!array_key_exists('union_id', $row) || $row['union_id'] === null) {
            throw AppException::forbidden('Cross-union access denied.');
        }
        $raw = $row['union_id'];
        // Strict: must be an integer or integer-string, nothing else
        if (is_int($raw)) {
            $rowUnion = $raw;
        } elseif (is_string($raw) && preg_match('/^-?\d+$/', trim($raw))) {
            $rowUnion = (int) trim($raw);
        } else {
            throw AppException::forbidden('Cross-union access denied.');
        }
        if ($rowUnion !== $allowed) {
            throw AppException::forbidden('Cross-union access denied.');
        }
    }

    /**
     * For create payloads: force union_id to caller's scope when restricted.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function scopeCreateData(Request $req, array $data): array
    {
        $allowed = self::allowedUnionId($req);
        if ($allowed !== null) {
            $data['union_id'] = $allowed;
        }
        return $data;
    }
}
