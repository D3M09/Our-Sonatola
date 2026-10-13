<?php
declare(strict_types=1);

final class PublicController
{
    private PDO $pdo;
    private ContentRepository $content;
    private DirectoryRepository $dir;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->content = new ContentRepository($pdo);
        $this->dir = new DirectoryRepository($pdo);
    }

    public function health(Request $req, array $params): void
    {
        Response::json(['app' => 'Sonatola Upazila Digital Platform', 'version' => 'v1', 'time' => gmdate('c')]);
    }

    public function news(Request $req, array $params): void
    {
        [$page, $per, ] = Validator::paginate(array_merge($req->query, $req->body));
        $cat = isset($req->query['category_id']) ? (int) $req->query['category_id'] : null;
        $q = isset($req->query['q']) ? trim((string) $req->query['q']) : null;
        $r = $this->content->listNews($page, $per, 'published', $cat, $q);
        Response::paginated($r['items'], $page, $per, $r['total']);
    }

    public function newsShow(Request $req, array $params): void
    {
        $slug = $params['slug'] ?? '';
        $row = $this->content->findNewsBySlug($slug);
        if (!$row || ($row['status'] ?? '') !== 'published') {
            throw AppException::notFound('News not found.');
        }
        Response::json($row);
    }

    public function notices(Request $req, array $params): void
    {
        [$page, $per, ] = Validator::paginate(array_merge($req->query, $req->body));
        $q = isset($req->query['q']) ? trim((string) $req->query['q']) : null;
        $r = $this->content->listNotices($page, $per, 'published', $q, null, true);
        Response::paginated($r['items'], $page, $per, $r['total']);
    }

    public function unions(Request $req, array $params): void
    {
        Response::json($this->dir->unions());
    }

    public function services(Request $req, array $params): void
    {
        [$page, $per, ] = Validator::paginate(array_merge($req->query, $req->body));
        $q = isset($req->query['q']) ? trim((string) $req->query['q']) : null;
        $r = $this->dir->listServices($page, $per, $q);
        Response::paginated($r['items'], $page, $per, $r['total']);
    }

    public function categories(Request $req, array $params): void
    {
        Response::json($this->content->categories());
    }
}
