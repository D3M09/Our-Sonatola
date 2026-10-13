<?php
declare(strict_types=1);

final class UserRepository extends BaseRepository
{
    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
        $st->execute([':e' => $email]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function create(array $d): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO users (name, email, phone, password_hash, union_id, upazila_id, account_status)
             VALUES (:n,:e,:p,:h,:u,:up,:s)'
        );
        $st->execute([
            ':n' => $d['name'],
            ':e' => $d['email'],
            ':p' => $d['phone'] ?? null,
            ':h' => $d['password_hash'],
            ':u' => $d['union_id'] ?? null,
            ':up' => $d['upazila_id'] ?? null,
            ':s' => $d['account_status'] ?? 'pending',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setStatus(int $id, string $status): void
    {
        $st = $this->pdo->prepare("UPDATE users SET account_status = :s WHERE id = :id");
        $st->execute([':s' => $status, ':id' => $id]);
    }

    public function updatePassword(int $id, string $hash): void
    {
        $st = $this->pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
        $st->execute([':h' => $hash, ':id' => $id]);
    }

    public function touchLogin(int $id): void
    {
        $st = $this->pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id');
        $st->execute([':now' => gmdate('Y-m-d H:i:s'), ':id' => $id]);
    }

    public function assignRole(int $userId, int $roleId): void
    {
        $st = $this->pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:u,:r)');
        $st->execute([':u' => $userId, ':r' => $roleId]);
    }

    public function roleIdBySlug(string $slug): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM roles WHERE slug = :s LIMIT 1');
        $st->execute([':s' => $slug]);
        $r = $st->fetch();
        return $r ? (int) $r['id'] : null;
    }

    /** Count other active super_admins excluding a user (for last-admin guard). */
    public function countOtherActiveSuperAdmins(int $excludeUserId): int
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT u.id) FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id AND r.slug = 'super_admin'
             WHERE u.id <> :ex AND u.account_status = 'active'"
        );
        $st->execute([':ex' => $excludeUserId]);
        return (int) $st->fetchColumn();
    }

    /** Whether a user currently holds the super_admin role. */
    public function isSuperAdmin(int $userId): bool
    {
        $st = $this->pdo->prepare(
            "SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :u AND r.slug = 'super_admin' LIMIT 1"
        );
        $st->execute([':u' => $userId]);
        return (bool) $st->fetchColumn();
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function list(int $page, int $perPage, ?string $status = null, ?string $q = null, ?int $unionId = null): array
    {
        $where = 'WHERE 1=1';
        $p = [];
        if ($status) {
            $where .= ' AND u.account_status = :s';
            $p[':s'] = $status;
        }
        if ($q) {
            // NOTE: each LIKE gets its own placeholder — native MySQL prepares
            // reject a repeated named placeholder (HY093); emulated prepares
            // tolerate it, so this keeps both modes working identically.
            $where .= ' AND (u.name LIKE :q1 ESCAPE \'!\' OR u.email LIKE :q2 ESCAPE \'!\' OR u.phone LIKE :q3 ESCAPE \'!\')';
            $p[':q1'] = $p[':q2'] = $p[':q3'] = self::likePattern($q);
        }
        if ($unionId !== null) {
            $where .= ' AND u.union_id = :u';
            $p[':u'] = $unionId;
        }
        return $this->paginate(
            "SELECT COUNT(*) FROM users u {$where}",
            "SELECT u.id,u.name,u.email,u.phone,u.union_id,u.upazila_id,u.account_status,u.email_verified_at,u.last_login_at,u.created_at FROM users u {$where} ORDER BY u.id DESC",
            $p,
            $page,
            $perPage
        );
    }
}
