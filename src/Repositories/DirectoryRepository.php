<?php
declare(strict_types=1);

final class DirectoryRepository extends BaseRepository
{
    /** @return array<int,array<string,mixed>> */
    public function upazilas(): array
    {
        return $this->pdo->query('SELECT * FROM upazilas ORDER BY id')->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function unions(?int $upazilaId = null): array
    {
        if ($upazilaId) {
            $st = $this->pdo->prepare('SELECT * FROM unions WHERE upazila_id=:u AND is_active=1 ORDER BY name_en');
            $st->execute([':u' => $upazilaId]);
            return $st->fetchAll();
        }
        return $this->pdo->query('SELECT * FROM unions WHERE is_active=1 ORDER BY name_en')->fetchAll();
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function listServices(int $page, int $perPage, ?string $q = null, ?int $unionId = null): array
    {
        $where = 'WHERE is_active=1';
        $p = [];
        if ($q) {
            // Distinct placeholders per LIKE (native prepares reject repeats — see UserRepository).
            $where .= ' AND (name_en LIKE :q1 ESCAPE \'!\' OR name_bn LIKE :q2 ESCAPE \'!\' OR office_name LIKE :q3 ESCAPE \'!\')';
            $p[':q1'] = $p[':q2'] = $p[':q3'] = self::likePattern($q);
        }
        if ($unionId) {
            $where .= ' AND (union_id = :u OR union_id IS NULL)';
            $p[':u'] = $unionId;
        }
        return $this->paginate(
            "SELECT COUNT(*) FROM services {$where}",
            "SELECT * FROM services {$where} ORDER BY sort_order, name_en",
            $p,
            $page,
            $perPage
        );
    }
}
