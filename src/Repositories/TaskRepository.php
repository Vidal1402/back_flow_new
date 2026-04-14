<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class TaskRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function allByOrganization(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.title, t.type, t.priority, t.due_date, t.status, t.owner_id,
                    u.name AS owner_name, t.created_at, t.updated_at
             FROM tasks t
             LEFT JOIN users u ON u.id = t.owner_id
             WHERE t.organization_id = :org
             ORDER BY t.id DESC'
        );
        $stmt->execute(['org' => $organizationId]);
        return $stmt->fetchAll() ?: [];
    }

    public function create(array $payload, int $organizationId, int $ownerId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (title, type, priority, due_date, status, owner_id, organization_id)
             VALUES (:title, :type, :priority, :due_date, :status, :owner_id, :organization_id)'
        );
        $stmt->execute([
            'title' => $payload['title'],
            'type' => $payload['type'] ?? 'Outros',
            'priority' => $payload['priority'] ?? 'Média',
            'due_date' => $payload['due_date'] ?? null,
            'status' => $payload['status'] ?? 'solicitacoes',
            'owner_id' => $payload['owner_id'] ?? $ownerId,
            'organization_id' => $organizationId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateStatus(int $taskId, string $status, int $organizationId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tasks
             SET status = :status, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND organization_id = :org'
        );
        $stmt->execute([
            'status' => $status,
            'id' => $taskId,
            'org' => $organizationId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
