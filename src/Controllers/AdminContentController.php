<?php
declare(strict_types=1);

final class AdminContentController
{
    private PDO $pdo;
    private ContentRepository $repo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->repo = new ContentRepository($pdo);
    }

    private function slugify(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = preg_replace('/[^a-z0-9\x{0980}-\x{09FF}\s-]/u', '', $t) ?? '';
        $t = preg_replace('/[\s_]+/u', '-', (string) $t) ?? '';
        $t = trim((string) $t, '-');
        return substr($t !== '' ? $t : 'item', 0, 180) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    // ---- News ----
    public function newsList(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'news.view');
        [$page, $per, ] = Validator::paginate(array_merge($req->query, $req->body));
        $unionId = RbacMiddleware::allowedUnionId($req);
        $r = $this->repo->listNews($page, $per, null, null, null, $unionId);
        Response::paginated($r['items'], $page, $per, $r['total']);
    }

    public function newsCreate(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'news.create');
        Csrf::requireValid($req);
        $errors = Validator::validate($req->body, ['title' => 'required|max:255', 'body' => 'required|min:10']);
        if ($errors !== []) {
            throw new AppException('Validation failed.', 422, 'validation_failed');
        }
        $data = RbacMiddleware::scopeCreateData($req, $req->body);
        $status = in_array(($data['status'] ?? ''), ['draft', 'published', 'archived'], true) ? $data['status'] : 'draft';
        $id = $this->repo->createNews([
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'union_id' => isset($data['union_id']) ? (int) $data['union_id'] : null,
            'upazila_id' => $req->user['upazila_id'] ?? null,
            'author_id' => (int) $req->user['id'],
            'title' => trim((string) $data['title']),
            'slug' => $this->slugify((string) $data['title']),
            'excerpt' => isset($data['excerpt']) ? substr(trim((string) $data['excerpt']), 0, 500) : null,
            'body' => (string) $data['body'],
            'status' => $status,
            'published_at' => $status === 'published' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        Audit::log($this->pdo, (int) $req->user['id'], 'news.create', 'news', $id, $req);
        Response::json($this->repo->findNewsById($id), 201);
    }

    public function newsUpdate(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'news.edit');
        Csrf::requireValid($req);
        $id = (int) ($params['id'] ?? 0);
        $row = $this->repo->findNewsById($id);
        if (!$row) {
            throw AppException::notFound('News not found.');
        }
        RbacMiddleware::assertUnionAccess($req, $row);
        $errors = Validator::validate($req->body, ['title' => 'required|max:255', 'body' => 'required|min:10']);
        if ($errors !== []) {
            throw new AppException('Validation failed.', 422, 'validation_failed');
        }
        $status = in_array(($req->body['status'] ?? $row['status']), ['draft', 'published', 'archived'], true)
            ? (string) ($req->body['status'] ?? $row['status']) : 'draft';
        $this->repo->updateNews($id, [
            'category_id' => isset($req->body['category_id']) ? (int) $req->body['category_id'] : ($row['category_id'] ?? null),
            'title' => trim((string) $req->body['title']),
            'slug' => $row['slug'],
            'excerpt' => isset($req->body['excerpt']) ? substr(trim((string) $req->body['excerpt']), 0, 500) : ($row['excerpt'] ?? null),
            'body' => (string) $req->body['body'],
            'status' => $status,
            'published_at' => $status === 'published' ? ($row['published_at'] ?? gmdate('Y-m-d H:i:s')) : null,
        ]);
        Audit::log($this->pdo, (int) $req->user['id'], 'news.update', 'news', $id, $req);
        Response::json($this->repo->findNewsById($id));
    }

    public function newsDelete(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'news.delete');
        Csrf::requireValid($req);
        $id = (int) ($params['id'] ?? 0);
        $row = $this->repo->findNewsById($id);
        if (!$row) {
            throw AppException::notFound('News not found.');
        }
        RbacMiddleware::assertUnionAccess($req, $row);
        $this->repo->deleteNews($id);
        Audit::log($this->pdo, (int) $req->user['id'], 'news.delete', 'news', $id, $req);
        Response::json(['message' => 'Deleted.']);
    }

    // ---- Notices ----
    public function noticeList(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'notice.view');
        [$page, $per, ] = Validator::paginate(array_merge($req->query, $req->body));
        $unionId = RbacMiddleware::allowedUnionId($req);
        $r = $this->repo->listNotices($page, $per, null, null, $unionId, false);
        Response::paginated($r['items'], $page, $per, $r['total']);
    }

    public function noticeCreate(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'notice.create');
        Csrf::requireValid($req);
        $errors = Validator::validate($req->body, ['title' => 'required|max:255', 'body' => 'required|min:10']);
        if ($errors !== []) {
            throw new AppException('Validation failed.', 422, 'validation_failed');
        }
        $data = RbacMiddleware::scopeCreateData($req, $req->body);
        $status = in_array(($data['status'] ?? ''), ['draft', 'published', 'archived'], true) ? $data['status'] : 'draft';
        $id = $this->repo->createNotice([
            'union_id' => isset($data['union_id']) ? (int) $data['union_id'] : null,
            'upazila_id' => $req->user['upazila_id'] ?? null,
            'author_id' => (int) $req->user['id'],
            'title' => trim((string) $data['title']),
            'body' => (string) $data['body'],
            'status' => $status,
            'published_at' => $status === 'published' ? gmdate('Y-m-d H:i:s') : null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        Audit::log($this->pdo, (int) $req->user['id'], 'notice.create', 'notices', $id, $req);
        Response::json($this->repo->findNotice($id), 201);
    }

    public function noticeUpdate(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'notice.edit');
        Csrf::requireValid($req);
        $id = (int) ($params['id'] ?? 0);
        $row = $this->repo->findNotice($id);
        if (!$row) {
            throw AppException::notFound('Notice not found.');
        }
        RbacMiddleware::assertUnionAccess($req, $row);
        $errors = Validator::validate($req->body, ['title' => 'required|max:255', 'body' => 'required|min:10']);
        if ($errors !== []) {
            throw new AppException('Validation failed.', 422, 'validation_failed');
        }
        $status = in_array(($req->body['status'] ?? $row['status']), ['draft', 'published', 'archived'], true)
            ? (string) ($req->body['status'] ?? $row['status']) : 'draft';
        $this->repo->updateNotice($id, [
            'title' => trim((string) $req->body['title']),
            'body' => (string) $req->body['body'],
            'status' => $status,
            'published_at' => $status === 'published' ? ($row['published_at'] ?? gmdate('Y-m-d H:i:s')) : null,
            'expires_at' => $req->body['expires_at'] ?? ($row['expires_at'] ?? null),
        ]);
        Audit::log($this->pdo, (int) $req->user['id'], 'notice.update', 'notices', $id, $req);
        Response::json($this->repo->findNotice($id));
    }

    public function noticeDelete(Request $req, array $params): void
    {
        RbacMiddleware::requirePermission($req, 'notice.delete');
        Csrf::requireValid($req);
        $id = (int) ($params['id'] ?? 0);
        $row = $this->repo->findNotice($id);
        if (!$row) {
            throw AppException::notFound('Notice not found.');
        }
        RbacMiddleware::assertUnionAccess($req, $row);
        $this->repo->deleteNotice($id);
        Audit::log($this->pdo, (int) $req->user['id'], 'notice.delete', 'notices', $id, $req);
        Response::json(['message' => 'Deleted.']);
    }
}
