<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BsonUtil;
use App\Core\Sequence;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database as MongoDatabase;

final class TaskRepository
{
    public function __construct(
        private readonly MongoDatabase $db,
        private readonly Sequence $sequence
    )
    {
    }

    public function allByOrganization(int $organizationId): array
    {
        return $this->queryTasksMapped(['organization_id' => $organizationId]);
    }

    /**
     * Tarefas visíveis no portal para perfil cliente: vinculadas ao cadastro (client_id) ou legadas só com owner_id.
     */
    public function allForPortalCliente(int $organizationId, int $userId, ?int $linkedClientId): array
    {
        if ($linkedClientId !== null && $linkedClientId > 0) {
            $filter = [
                'organization_id' => $organizationId,
                '$or' => [
                    ['client_id' => $linkedClientId],
                    [
                        '$and' => [
                            ['owner_id' => $userId],
                            [
                                '$or' => [
                                    ['client_id' => ['$exists' => false]],
                                    ['client_id' => null],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        } else {
            $filter = [
                'organization_id' => $organizationId,
                'owner_id' => $userId,
            ];
        }

        return $this->queryTasksMapped($filter);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rawByOrganizationAndId(int $organizationId, int $taskId): ?array
    {
        $doc = $this->db->selectCollection('tasks')->findOne([
            'organization_id' => $organizationId,
            'id' => $taskId,
        ]);

        return $doc ? $doc->getArrayCopy() : null;
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function portalClienteCanAccessTask(array $raw, int $userId, ?int $linkedClientId): bool
    {
        $ownerId = (int) ($raw['owner_id'] ?? 0);
        $cid = isset($raw['client_id']) && $raw['client_id'] !== null ? (int) $raw['client_id'] : 0;

        if ($linkedClientId !== null && $linkedClientId > 0) {
            if ($cid === $linkedClientId) {
                return true;
            }

            return $cid <= 0 && $ownerId === $userId;
        }

        return $ownerId === $userId;
    }

    private function queryTasksMapped(array $filter): array
    {
        $cursor = $this->db->selectCollection('tasks')->find($filter, ['sort' => ['id' => -1]]);

        $rows = [];
        $ownerIds = [];
        foreach ($cursor as $doc) {
            $row = $doc->getArrayCopy();
            $rows[] = $row;
            $ownerId = (int) ($row['owner_id'] ?? 0);
            if ($ownerId > 0) {
                $ownerIds[] = $ownerId;
            }
        }

        $ownerNamesById = [];
        if ($ownerIds !== []) {
            $usersCursor = $this->db->selectCollection('users')->find(
                ['id' => ['$in' => array_values(array_unique($ownerIds))]],
                ['projection' => ['id' => 1, 'name' => 1]]
            );
            foreach ($usersCursor as $doc) {
                $user = $doc->getArrayCopy();
                $ownerNamesById[(int) ($user['id'] ?? 0)] = (string) ($user['name'] ?? '');
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $ownerId = (int) ($row['owner_id'] ?? 0);
            $clientId = isset($row['client_id']) && $row['client_id'] !== null ? (int) $row['client_id'] : null;
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'type' => (string) ($row['type'] ?? 'Outros'),
                'priority' => (string) ($row['priority'] ?? 'Média'),
                'due_date' => $row['due_date'] ?? null,
                'status' => (string) ($row['status'] ?? 'solicitacoes'),
                'owner_id' => $ownerId,
                'owner_name' => $ownerNamesById[$ownerId] ?? null,
                'client_id' => $clientId !== null && $clientId > 0 ? $clientId : null,
                'created_at' => BsonUtil::formatDate($row['created_at'] ?? null),
                'updated_at' => BsonUtil::formatDate($row['updated_at'] ?? null),
            ];
        }

        return $items;
    }

    public function create(array $payload, int $organizationId, int $ownerId): int
    {
        $id = $this->sequence->next('tasks');
        $now = new UTCDateTime();
        $doc = [
            'id' => $id,
            'title' => (string) $payload['title'],
            'type' => (string) ($payload['type'] ?? 'Outros'),
            'priority' => (string) ($payload['priority'] ?? 'Média'),
            'due_date' => $payload['due_date'] ?? null,
            'status' => (string) ($payload['status'] ?? 'solicitacoes'),
            'owner_id' => (int) ($payload['owner_id'] ?? $ownerId),
            'organization_id' => $organizationId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $cid = isset($payload['client_id']) ? (int) $payload['client_id'] : 0;
        if ($cid > 0) {
            $doc['client_id'] = $cid;
        }

        $this->db->selectCollection('tasks')->insertOne($doc);

        return $id;
    }

    public function updateStatus(int $taskId, string $status, int $organizationId): bool
    {
        $result = $this->db->selectCollection('tasks')->updateOne(
            ['id' => $taskId, 'organization_id' => $organizationId],
            ['$set' => ['status' => $status, 'updated_at' => new UTCDateTime()]]
        );

        return $result->getMatchedCount() > 0;
    }
}
