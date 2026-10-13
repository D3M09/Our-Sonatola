<?php
declare(strict_types=1);

/**
 * Explicit union-scope policy.
 * Replaces hardcoded role-name checks scattered through middleware.
 *
 * Union-scoped roles see only their own union (plus upazila-wide NULL-union
 * content where applicable). Global roles see all unions.
 *
 * To add a future role (e.g. volunteer_coordinator): insert it into roles with
 * scope='union' (see 011_roles_scope.sql) and add its slug to UNION_SCOPED as
 * fallback for installs predating the scope column. No middleware changes needed.
 */
final class ScopePolicy
{
    /** Fallback for databases predating roles.scope column. */
    public const UNION_SCOPED = ['union_admin', 'service_officer', 'volunteer_coordinator'];

    /** @param array<int,string> $roles */
    public static function isUnionScoped(array $roles, ?PDO $pdo = null): bool
    {
        // Prefer DB-driven scope when the column exists.
        if ($pdo !== null) {
            try {
                $placeholders = implode(',', array_fill(0, count($roles), '?'));
                if ($placeholders === '') {
                    return false;
                }
                $st = $pdo->prepare("SELECT slug, scope FROM roles WHERE slug IN ({$placeholders})");
                // $placeholders is built from count() only, roles bound as params — safe.
                $st->execute(array_values($roles));
                $rows = $st->fetchAll();
                if ($rows !== []) {
                    foreach ($rows as $r) {
                        if (($r['scope'] ?? '') === 'union') {
                            return true;
                        }
                    }
                    return false;
                }
            } catch (Throwable) {
                // Column/table missing (old install): fall through to constant map.
            }
        }
        foreach ($roles as $slug) {
            if (in_array($slug, self::UNION_SCOPED, true)) {
                return true;
            }
        }
        return false;
    }
}
