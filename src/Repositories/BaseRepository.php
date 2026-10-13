<?php
declare(strict_types=1);

/** Shared query helpers. */
abstract class BaseRepository
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    protected function paginate(string $countSql, string $dataSql, array $params, int $page, int $perPage): array
    {
        $st = $this->pdo->prepare($countSql);
        $st->execute($params);
        $total = (int) ($st->fetchColumn() ?: 0);
        $offset = ($page - 1) * $perPage;
        $st = $this->pdo->prepare($dataSql . ' LIMIT ' . ((int) $perPage) . ' OFFSET ' . ((int) $offset));
        $st->execute($params);
        return ['items' => $st->fetchAll(), 'total' => $total];
    }

    /**
     * Build a LIKE pattern from raw user input with !, % and _ escaped, so a
     * search term can only match literally (use with ESCAPE '!' in the SQL).
     * The escape character is '!' rather than backslash: under utf8mb4_unicode_ci
     * (MySQL 5.7/MariaDB) a backslash ESCAPE does not match literal backslashes,
     * while '!' behaves identically on every supported collation.
     */
    protected static function likePattern(string $q): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
    }
}
