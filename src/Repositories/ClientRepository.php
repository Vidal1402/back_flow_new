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
            $row = $this->ensureNumericId($row, $organizationId);
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

    /**
     * Corrige clientes legados sem id numérico na primeira listagem.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function ensureNumericId(array $row, int $organizationId): array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            return $row;
        }

        if (!array_key_exists('_id', $row)) {
            return $row;
        }

        $newId = $this->pickNextAvailableId();
        $this->db->selectCollection('clients')->updateOne(
            ['_id' => $row['_id'], 'organization_id' => $organizationId],
            ['$set' => ['id' => $newId, 'updated_at' => new UTCDateTime()]]
        );
        $row['id'] = $newId;

        return $row;
    }

    private function pickNextAvailableId(): int
    {
        $collection = $this->db->selectCollection('clients');
        do {
            $candidate = $this->sequence->next('clients');
            $exists = $collection->findOne(['id' => $candidate], ['projection' => ['_id' => 1]]);
        } while ($exists !== null);

        return $candidate;
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
