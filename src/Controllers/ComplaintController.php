<?php
declare(strict_types=1);

final class ComplaintController
{
    private PDO $pdo;
    private ComplaintService $svc;
    private ComplaintRepository $repo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->svc = new ComplaintService($pdo);
        $this->repo = new ComplaintRepository($pdo);
    }

    public function submit(Request $req, array $params): void
    {
        // CSRF for session-cookie browsers; Bearer-token API clients are exempt
        // inside Csrf::requireValid (checks $_SESSION['user_id']).
        Csrf::requireValid($req);
        RateLimit::check('complaint:' . $req->ip(), 20, 3600);
        $row = $this->svc->submit($req, $req->body);
        Response::json($row, 201);
    }

    public function mine(Request $req, array $params): void
    {
        AuthMiddleware::requireAuth($req);
        [$page, $per, ] = Validator::paginate(array_merge($req->query, $req->body));
        $r = $this->repo->listMine((int) $req->user['id'], $page, $per);
        Response::paginated($r['items'], $page, $per, $r['total']);
    }

    public function show(Request $req, array $params): void
    {
        AuthMiddleware::requireAuth($req);
        $id = (int) ($params['id'] ?? 0);
        $c = $this->repo->find($id);
        if (!$c) {
            throw AppException::notFound('Complaint not found.');
        }
        // Citizens may only see their own; staff need complaint.view + union scope.
        $roles = $req->user['roles'] ?? [];
        $isStaff = count(array_intersect($roles, ['super_admin', 'upazila_admin', 'union_admin', 'service_officer'])) > 0;
        if (!$isStaff && (int) $c['citizen_id'] !== (int) $req->user['id']) {
            throw AppException::forbidden();
        }
        if ($isStaff) {
            RbacMiddleware::requirePermission($req, 'complaint.view');
            RbacMiddleware::assertUnionAccess($req, $c);
        }
        $c['history'] = $this->repo->history($id);
        Response::json($c);
    }

    public function listAll(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'complaint.view');
        [$page, $per, ] = Validator::paginate(array_merge($req->query, $req->body));
        $status = isset($req->query['status']) ? trim((string) $req->query['status']) : null;
        if ($status !== null && $status !== '' && !in_array($status, ['submitted', 'in_review', 'assigned', 'resolved', 'rejected', 'closed'], true)) {
            throw AppException::unprocessable('Invalid status filter.');
        }
        $unionId = RbacMiddleware::allowedUnionId($req);
        // Upazila staff may optionally filter ?union_id= ; union staff forced to own
        if ($unionId === null && isset($req->query['union_id']) && RbacMiddleware::isSuperAdmin($req) === false) {
            // upazila_admin may filter; validate int
            $f = (int) $req->query['union_id'];
            if ($f > 0) {
                $unionId = $f;
            }
        } elseif ($unionId === null && isset($req->query['union_id'])) {
            $f = (int) $req->query['union_id'];
            if ($f > 0) {
                $unionId = $f;
            }
        }
        $r = $this->repo->listScoped($page, $per, $unionId, $status);
        Response::paginated($r['items'], $page, $per, $r['total']);
    }

    public function assign(Request $req, array $params): void
    {
        Csrf::requireValid($req);
        $id = (int) ($params['id'] ?? 0);
        $to = (int) ($req->body['assigned_to'] ?? 0);
        if ($to <= 0) {
            throw AppException::unprocessable('assigned_to is required.');
        }
        $row = $this->svc->assign($req, $id, $to, isset($req->body['note']) ? (string) $req->body['note'] : null);
        Response::json($row);
    }

    public function status(Request $req, array $params): void
    {
        Csrf::requireValid($req);
        $id = (int) ($params['id'] ?? 0);
        $to = trim((string) ($req->body['status'] ?? ''));
        $row = $this->svc->changeStatus($req, $id, $to, isset($req->body['note']) ? (string) $req->body['note'] : null);
        Response::json($row);
    }
}
