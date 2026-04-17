<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BsonUtil;
use App\Core\Sequence;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database as MongoDatabase;

final class ClientRepository
{
    public function __construct(
        private readonly MongoDatabase $db,
        private readonly Sequence $sequence
    )
    {
    }

    public function allByOrganization(int $organizationId): array
    {
        $cursor = $this->db->selectCollection('clients')->find(
            ['organization_id' => $organizationId],
            ['sort' => ['id' => -1]]
        );

        $items = [];
        foreach ($cursor as $doc) {
            $row = $doc->getArrayCopy();
            $items[] = $this->mapClientRow($row);
        }

        return $items;
    }

    public function create(array $payload, int $organizationId): int
    {
        $id = $this->sequence->next('clients');
        $now = new UTCDateTime();
        $this->db->selectCollection('clients')->insertOne([
            'id' => $id,
            'name' => (string) $payload['name'],
            'empresa' => (string) $payload['empresa'],
            'email' => mb_strtolower(trim((string) $payload['email'])),
            'telefone' => $payload['telefone'] ?? null,
            'plano' => (string) ($payload['plano'] ?? 'Growth'),
            'valor' => (float) ($payload['valor'] ?? 0),
            'status' => (string) ($payload['status'] ?? 'ativo'),
            'organization_id' => $organizationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    public function findByOrganizationAndId(int $organizationId, int $id): ?array
    {
        $doc = $this->db->selectCollection('clients')->findOne([
            'organization_id' => $organizationId,
            'id' => $id,
        ]);
        if (!$doc) {
            return null;
        }
        return $this->mapClientRow($doc->getArrayCopy());
    }

    public function findByUserId(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $doc = $this->db->selectCollection('clients')->findOne(['user_id' => $userId]);
        if (!$doc) {
            return null;
        }

        return $this->mapClientRow($doc->getArrayCopy());
    }

    public function findByOrganizationAndEmail(int $organizationId, string $email): ?array
    {
        $normalizedEmail = mb_strtolower(trim($email));
        if ($normalizedEmail === '') {
            return null;
        }

        $doc = $this->db->selectCollection('clients')->findOne([
            'organization_id' => $organizationId,
            'email' => $normalizedEmail,
        ]);
        if (!$doc) {
            return null;
        }

        return $this->mapClientRow($doc->getArrayCopy());
    }

    public function bindUserByOrganizationAndEmail(int $organizationId, string $email, int $userId): bool
    {
        $normalizedEmail = mb_strtolower(trim($email));
        if ($organizationId <= 0 || $userId <= 0 || $normalizedEmail === '') {
            return false;
        }

        $result = $this->db->selectCollection('clients')->updateOne(
            [
                'organization_id' => $organizationId,
                'email' => $normalizedEmail,
            ],
            [
                '$set' => [
                    'user_id' => $userId,
                    'updated_at' => new UTCDateTime(),
                ],
            ]
        );

        return $result->getMatchedCount() > 0;
    }

    /**
     * @param array{name?:string, empresa?:string, email?:string, telefone?:string|null, plano?:string, valor?:float|int, status?:string} $payload
     */
    public function updateForOrganization(int $organizationId, int $id, array $payload): bool
    {
        $set = [];
        if (array_key_exists('name', $payload)) {
            $set['name'] = (string) $payload['name'];
        }
        if (array_key_exists('empresa', $payload)) {
            $set['empresa'] = (string) $payload['empresa'];
        }
        if (array_key_exists('email', $payload)) {
            $set['email'] = mb_strtolower(trim((string) $payload['email']));
        }
        if (array_key_exists('telefone', $payload)) {
            $set['telefone'] = $payload['telefone'] === '' ? null : ($payload['telefone'] ?? null);
        }
        if (array_key_exists('plano', $payload)) {
            $set['plano'] = (string) $payload['plano'];
        }
        if (array_key_exists('valor', $payload)) {
            $set['valor'] = (float) $payload['valor'];
        }
        if (array_key_exists('status', $payload)) {
            $set['status'] = (string) $payload['status'];
        }

        if ($set === []) {
            return false;
        }

        $set['updated_at'] = new UTCDateTime();
        $result = $this->db->selectCollection('clients')->updateOne(
            ['organization_id' => $organizationId, 'id' => $id],
            ['$set' => $set]
        );

        return $result->getMatchedCount() > 0;
    }

    public function deleteForOrganization(int $organizationId, int $id): bool
    {
        $result = $this->db->selectCollection('clients')->deleteOne([
            'organization_id' => $organizationId,
            'id' => $id,
        ]);
        return $result->getDeletedCount() > 0;
    }

    private function mapClientRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'empresa' => (string) ($row['empresa'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'telefone' => $row['telefone'] ?? null,
            'plano' => (string) ($row['plano'] ?? 'Growth'),
            'valor' => (float) ($row['valor'] ?? 0),
            'status' => (string) ($row['status'] ?? 'ativo'),
            'organization_id' => (int) ($row['organization_id'] ?? 1),
            'created_at' => BsonUtil::formatDate($row['created_at'] ?? null),
        ];
    }
}
