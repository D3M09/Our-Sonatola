<?php
declare(strict_types=1);

final class ContentRepository extends BaseRepository
{
    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function listNews(int $page, int $perPage, ?string $status = 'published', ?int $categoryId = null, ?string $q = null, ?int $unionId = null): array
    {
        $where = 'WHERE 1=1';
        $p = [];
        if ($status) {
            $where .= ' AND n.status = :s';
            $p[':s'] = $status;
        }
        if ($categoryId) {
            $where .= ' AND n.category_id = :c';
            $p[':c'] = $categoryId;
        }
        if ($q) {
            // Distinct placeholders per LIKE (native prepares reject repeats — see UserRepository).
            $where .= ' AND (n.title LIKE :q1 ESCAPE \'!\' OR n.excerpt LIKE :q2 ESCAPE \'!\')';
            $p[':q1'] = $p[':q2'] = self::likePattern($q);
        }
        if ($unionId !== null) {
            // Union staff see their own union's items plus upazila-wide (NULL union) items.
            $where .= ' AND (n.union_id = :u OR n.union_id IS NULL)';
            $p[':u'] = $unionId;
        }
        return $this->paginate(
            "SELECT COUNT(*) FROM news n {$where}",
            "SELECT n.*, c.slug AS category_slug, c.name_en AS category_name FROM news n LEFT JOIN news_categories c ON c.id=n.category_id {$where} ORDER BY n.published_at DESC, n.id DESC",
            $p,
            $page,
            $perPage
        );
    }

    public function findNewsById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT n.*, c.slug AS category_slug FROM news n LEFT JOIN news_categories c ON c.id=n.category_id WHERE n.id=:id LIMIT 1');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function findNewsBySlug(string $slug): ?array
    {
        $st = $this->pdo->prepare('SELECT n.*, c.slug AS category_slug FROM news n LEFT JOIN news_categories c ON c.id=n.category_id WHERE n.slug=:s LIMIT 1');
        $st->execute([':s' => $slug]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function createNews(array $d): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO news (category_id, union_id, upazila_id, author_id, title, slug, excerpt, body, status, published_at)
             VALUES (:c,:u,:up,:a,:t,:s,:e,:b,:st,:p)'
        );
        $st->execute([
            ':c' => $d['category_id'] ?? null, ':u' => $d['union_id'] ?? null,
            ':up' => $d['upazila_id'] ?? null, ':a' => $d['author_id'] ?? null,
            ':t' => $d['title'], ':s' => $d['slug'], ':e' => $d['excerpt'] ?? null,
            ':b' => $d['body'], ':st' => $d['status'] ?? 'draft',
            ':p' => $d['published_at'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateNews(int $id, array $d): void
    {
        $st = $this->pdo->prepare(
            'UPDATE news SET category_id=:c, title=:t, slug=:s, excerpt=:e, body=:b, status=:st, published_at=:p WHERE id=:id'
        );
        $st->execute([
            ':c' => $d['category_id'] ?? null, ':t' => $d['title'], ':s' => $d['slug'],
            ':e' => $d['excerpt'] ?? null, ':b' => $d['body'], ':st' => $d['status'] ?? 'draft',
            ':p' => $d['published_at'] ?? null, ':id' => $id,
        ]);
    }

    public function deleteNews(int $id): void
    {
        $st = $this->pdo->prepare('DELETE FROM news WHERE id=:id');
        $st->execute([':id' => $id]);
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function listNotices(int $page, int $perPage, ?string $status = 'published', ?string $q = null, ?int $unionId = null, bool $hideExpired = false, ?string $now = null): array
    {
        $where = 'WHERE 1=1';
        $p = [];
        if ($status) {
            $where .= ' AND status = :s';
            $p[':s'] = $status;
        }
        if ($q) {
            // Distinct placeholders per LIKE (native prepares reject repeats — see UserRepository).
            $where .= ' AND (title LIKE :q1 ESCAPE \'!\' OR body LIKE :q2 ESCAPE \'!\')';
            $p[':q1'] = $p[':q2'] = self::likePattern($q);
        }
        if ($unionId !== null) {
            $where .= ' AND (union_id = :u OR union_id IS NULL)';
            $p[':u'] = $unionId;
        }
        if ($hideExpired) {
            // Public listings hide expired notices; admin listings show all.
            $where .= ' AND (expires_at IS NULL OR expires_at > :now)';
            $p[':now'] = $now ?? gmdate('Y-m-d H:i:s');
        }
        return $this->paginate(
            "SELECT COUNT(*) FROM notices {$where}",
            "SELECT * FROM notices {$where} ORDER BY published_at DESC, id DESC",
            $p,
            $page,
            $perPage
        );
    }

    public function findNotice(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM notices WHERE id=:id LIMIT 1');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function createNotice(array $d): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO notices (union_id, upazila_id, author_id, title, body, status, published_at, expires_at)
             VALUES (:u,:up,:a,:t,:b,:s,:p,:e)'
        );
        $st->execute([
            ':u' => $d['union_id'] ?? null, ':up' => $d['upazila_id'] ?? null,
            ':a' => $d['author_id'] ?? null, ':t' => $d['title'], ':b' => $d['body'],
            ':s' => $d['status'] ?? 'draft', ':p' => $d['published_at'] ?? null,
            ':e' => $d['expires_at'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateNotice(int $id, array $d): void
    {
        $st = $this->pdo->prepare(
            'UPDATE notices SET title=:t, body=:b, status=:s, published_at=:p, expires_at=:e WHERE id=:id'
        );
        $st->execute([
            ':t' => $d['title'], ':b' => $d['body'], ':s' => $d['status'] ?? 'draft',
            ':p' => $d['published_at'] ?? null, ':e' => $d['expires_at'] ?? null, ':id' => $id,
        ]);
    }

    public function deleteNotice(int $id): void
    {
        $st = $this->pdo->prepare('DELETE FROM notices WHERE id=:id');
        $st->execute([':id' => $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(): array
    {
        return $this->pdo->query('SELECT * FROM news_categories WHERE is_active=1 ORDER BY name_en')->fetchAll();
    }
}
