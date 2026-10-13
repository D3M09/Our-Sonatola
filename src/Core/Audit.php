<?php
declare(strict_types=1);

/** Append-only audit trail. Call from services on protected writes. */
final class Audit
{
    /** @param array<string,mixed> $meta */
    public static function log(PDO $pdo, ?int $actorId, string $action, ?string $entityType, mixed $entityId, Request $req, array $meta = []): void
    {
        try {
            $st = $pdo->prepare(
                'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, ip_address, user_agent, metadata_json)
                 VALUES (:a,:act,:et,:eid,:ip,:ua,:m)'
            );
            $st->execute([
                ':a' => $actorId,
                ':act' => substr($action, 0, 100),
                ':et' => $entityType ? substr($entityType, 0, 50) : null,
                ':eid' => $entityId !== null ? substr((string) $entityId, 0, 64) : null,
                ':ip' => substr($req->ip(), 0, 45),
                ':ua' => substr($req->userAgent(), 0, 512),
                ':m' => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (Throwable) {
            // Audit must never break the main flow
        }
    }
}
