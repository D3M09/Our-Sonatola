<?php
declare(strict_types=1);

final class AdminUserController
{
    private PDO $pdo;
    private UserRepository $users;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->users = new UserRepository($pdo);
    }

    public function listUsers(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'user.view');
        [$page, $per, ] = Validator::paginate(array_merge($req->query, $req->body));
        $status = isset($req->query['status']) ? trim((string) $req->query['status']) : null;
        if ($status !== null && $status !== '' && !in_array($status, ['pending', 'active', 'suspended', 'disabled'], true)) {
            throw AppException::unprocessable('Invalid status filter.');
        }
        $q = isset($req->query['q']) ? trim((string) $req->query['q']) : null;
        // Union-scoped staff only see users of their own union (plus unassigned? no — strict).
        $unionId = RbacMiddleware::allowedUnionId($req);
        $r = $this->users->list($page, $per, $status, $q, $unionId);
        Response::paginated($r['items'], $page, $per, $r['total']);
    }

    public function setStatus(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'user.manage');
        Csrf::requireValid($req);
        $id = (int) ($params['id'] ?? 0);
        $status = trim((string) ($req->body['account_status'] ?? ''));
        if (!in_array($status, ['pending', 'active', 'suspended', 'disabled'], true)) {
            throw AppException::unprocessable('Invalid status.');
        }
        $u = $this->users->findById($id);
        if (!$u) {
            throw AppException::notFound('User not found.');
        }
        // Prevent locking yourself out / disabling last super admin (basic guard)
        if ($id === (int) $req->user['id'] && $status !== 'active') {
            throw AppException::forbidden('You cannot change your own status.');
        }
        if ($status !== 'active' && $this->users->isSuperAdmin($id) && $this->users->countOtherActiveSuperAdmins($id) === 0) {
            throw AppException::forbidden('Cannot disable the last active Super Admin.');
        }
        $this->users->setStatus($id, $status);
        Audit::log($this->pdo, (int) $req->user['id'], 'user.status', 'users', $id, $req, ['to' => $status]);
        Response::json(['message' => 'Status updated.']);
    }

    public function assignRole(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'role.assign');
        Csrf::requireValid($req);
        $id = (int) ($params['id'] ?? 0);
        $slug = trim((string) ($req->body['role'] ?? ''));
        $u = $this->users->findById($id);
        if (!$u) {
            throw AppException::notFound('User not found.');
        }
        $roleId = $this->users->roleIdBySlug($slug);
        if (!$roleId) {
            throw AppException::unprocessable('Unknown role.');
        }
        // Only super_admin may grant super_admin
        if ($slug === 'super_admin' && !RbacMiddleware::isSuperAdmin($req)) {
            throw AppException::forbidden('Only Super Admin can grant super_admin.');
        }
        $this->users->assignRole($id, $roleId);
        Audit::log($this->pdo, (int) $req->user['id'], 'user.role_assign', 'users', $id, $req, ['role' => $slug]);
        Response::json(['message' => 'Role assigned.']);
    }

    public function listRoles(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'role.view');
        $roles = $this->pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
        Response::json($roles);
    }

    public function listPermissions(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'role.view');
        $perms = $this->pdo->query('SELECT * FROM permissions ORDER BY slug')->fetchAll();
        Response::json($perms);
    }

    public function auditLogs(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'audit.view');
        [$page, $per, $offset] = Validator::paginate(array_merge($req->query, $req->body));
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $st = $this->pdo->prepare('SELECT * FROM audit_logs ORDER BY id DESC LIMIT ' . ((int) $per) . ' OFFSET ' . ((int) $offset));
        $st->execute();
        Response::paginated($st->fetchAll(), $page, $per, $total);
    }
}
