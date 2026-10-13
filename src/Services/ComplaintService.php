<?php
declare(strict_types=1);

final class ComplaintService
{
    private PDO $pdo;
    private ComplaintRepository $repo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->repo = new ComplaintRepository($pdo);
    }

    /** @param array<string,mixed> $data */
    public function submit(Request $req, array $data): array
    {
        AuthMiddleware::requireAuth($req);
        $data = RbacMiddleware::scopeCreateData($req, $data);
        $errors = Validator::validate($data, [
            'subject' => 'required|max:200',
            'description' => 'required|min:10',
            'union_id' => 'required|int',
            'category' => 'max:100',
            'contact_phone' => 'phone|max:20',
            'priority' => 'in:low,normal,high,urgent',
        ]);
        if ($errors !== []) {
            throw new AppException('Validation failed.', 422, 'validation_failed');
        }
        $unionId = (int) $data['union_id'];
        // If caller is citizen without union, allow chosen union; if scoped staff, already forced.
        // Retry on tracking_no UNIQUE collision (random 8-hex; collision is unlikely but possible).
        $attempts = 0;
        while (true) {
            $attempts++;
            $tracking = $this->trackingNo();
            try {
                $id = $this->repo->create([
                    'tracking_no' => $tracking,
                    'citizen_id' => (int) $req->user['id'],
                    'union_id' => $unionId,
                    'upazila_id' => $req->user['upazila_id'] ?? ($data['upazila_id'] ?? null),
                    'category' => $data['category'] ?? null,
                    'subject' => trim((string) $data['subject']),
                    'description' => trim((string) $data['description']),
                    'location_text' => $data['location_text'] ?? null,
                    'contact_phone' => $data['contact_phone'] ?? ($req->user['phone'] ?? null),
                    'priority' => $data['priority'] ?? 'normal',
                ]);
                break;
            } catch (PDOException $e) {
                if ($attempts >= 5 || !self::isDuplicateKey($e)) {
                    throw $e;
                }
                // regenerate tracking_no and retry
            }
        }
        $this->repo->addHistory($id, null, 'submitted', (int) $req->user['id'], null);
        Audit::log($this->pdo, (int) $req->user['id'], 'complaint.submit', 'complaints', $id, $req, ['union_id' => $unionId]);
        $row = $this->repo->find($id);
        return $row ?: ['id' => $id, 'tracking_no' => $tracking];
    }

    public function assign(Request $req, int $complaintId, int $assigneeId, ?string $note): array
    {
        RbacMiddleware::requirePermission($req, 'complaint.assign');
        $c = $this->repo->find($complaintId);
        if (!$c) {
            throw AppException::notFound('Complaint not found.');
        }
        RbacMiddleware::assertUnionAccess($req, $c);
        $from = $c['status'];
        $this->repo->addAssignment($complaintId, $assigneeId, (int) $req->user['id'], $note);
        $this->repo->updateStatus($complaintId, 'assigned', $assigneeId);
        $this->repo->addHistory($complaintId, $from, 'assigned', (int) $req->user['id'], $note);
        Audit::log($this->pdo, (int) $req->user['id'], 'complaint.assign', 'complaints', $complaintId, $req, ['to' => $assigneeId]);
        return $this->repo->find($complaintId) ?: $c;
    }

    public function changeStatus(Request $req, int $complaintId, string $to, ?string $note): array
    {
        RbacMiddleware::requirePermission($req, 'complaint.status');
        $allowed = ['in_review', 'assigned', 'resolved', 'rejected', 'closed'];
        if (!in_array($to, $allowed, true)) {
            throw AppException::unprocessable('Invalid status.');
        }
        $c = $this->repo->find($complaintId);
        if (!$c) {
            throw AppException::notFound('Complaint not found.');
        }
        RbacMiddleware::assertUnionAccess($req, $c);
        $from = $c['status'];
        $this->repo->updateStatus($complaintId, $to);
        $this->repo->addHistory($complaintId, $from, $to, (int) $req->user['id'], $note);
        Audit::log($this->pdo, (int) $req->user['id'], 'complaint.status', 'complaints', $complaintId, $req, ['from' => $from, 'to' => $to]);
        return $this->repo->find($complaintId) ?: $c;
    }

    private function trackingNo(): string
    {
        return 'SN-' . gmdate('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    private static function isDuplicateKey(PDOException $e): bool
    {
        $sqlState = $e->getCode();
        $msg = $e->getMessage();
        return $sqlState === '23000' || str_contains($msg, 'UNIQUE') || str_contains($msg, 'uq_complaints_tracking') || str_contains($msg, 'tracking_no');
    }
}
