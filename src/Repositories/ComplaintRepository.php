<?php
declare(strict_types=1);

final class ComplaintRepository extends BaseRepository
{
    public function create(array $d): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO complaints (tracking_no, citizen_id, union_id, upazila_id, category, subject, description, location_text, contact_phone, status, priority)
             VALUES (:t,:c,:u,:up,:cat,:s,:de,:loc,:ph,:st,:pr)'
        );
        $st->execute([
            ':t' => $d['tracking_no'], ':c' => $d['citizen_id'], ':u' => $d['union_id'],
            ':up' => $d['upazila_id'] ?? null, ':cat' => $d['category'] ?? null,
            ':s' => $d['subject'], ':de' => $d['description'], ':loc' => $d['location_text'] ?? null,
            ':ph' => $d['contact_phone'] ?? null, ':st' => 'submitted', ':pr' => $d['priority'] ?? 'normal',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM complaints WHERE id=:id LIMIT 1');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function findByTracking(string $t): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM complaints WHERE tracking_no=:t LIMIT 1');
        $st->execute([':t' => $t]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function listMine(int $citizenId, int $page, int $perPage): array
    {
        return $this->paginate(
            'SELECT COUNT(*) FROM complaints WHERE citizen_id=:c',
            'SELECT * FROM complaints WHERE citizen_id=:c ORDER BY id DESC',
            [':c' => $citizenId],
            $page,
            $perPage
        );
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function listScoped(int $page, int $perPage, ?int $unionId, ?string $status = null): array
    {
        $where = 'WHERE 1=1';
        $p = [];
        if ($unionId !== null) {
            $where .= ' AND union_id=:u';
            $p[':u'] = $unionId;
        }
        if ($status) {
            $where .= ' AND status=:s';
            $p[':s'] = $status;
        }
        return $this->paginate(
            "SELECT COUNT(*) FROM complaints {$where}",
            "SELECT * FROM complaints {$where} ORDER BY id DESC",
            $p,
            $page,
            $perPage
        );
    }

    public function updateStatus(int $id, string $to, ?int $assignee = null): void
    {
        if ($assignee !== null) {
            $st = $this->pdo->prepare('UPDATE complaints SET status=:s, assigned_to=:a WHERE id=:id');
            $st->execute([':s' => $to, ':a' => $assignee, ':id' => $id]);
        } else {
            $st = $this->pdo->prepare('UPDATE complaints SET status=:s WHERE id=:id');
            $st->execute([':s' => $to, ':id' => $id]);
        }
    }

    public function addAssignment(int $cid, int $to, int $by, ?string $note): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO complaint_assignments (complaint_id, assigned_to, assigned_by, note) VALUES (:c,:t,:b,:n)'
        );
        $st->execute([':c' => $cid, ':t' => $to, ':b' => $by, ':n' => $note]);
    }

    public function addHistory(int $cid, ?string $from, string $to, int $by, ?string $note): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO complaint_status_history (complaint_id, from_status, to_status, changed_by, note) VALUES (:c,:f,:t,:b,:n)'
        );
        $st->execute([':c' => $cid, ':f' => $from, ':t' => $to, ':b' => $by, ':n' => $note]);
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $cid): array
    {
        $st = $this->pdo->prepare('SELECT * FROM complaint_status_history WHERE complaint_id=:c ORDER BY id');
        $st->execute([':c' => $cid]);
        return $st->fetchAll();
    }
}
