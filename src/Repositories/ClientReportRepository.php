<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BsonUtil;
use App\Core\Sequence;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database as MongoDatabase;

final class ClientReportRepository
{
    public function __construct(
        private readonly MongoDatabase $db,
        private readonly Sequence $sequence
    ) {
    }

    public function allByOrganization(int $organizationId): array
    {
        $cursor = $this->db->selectCollection('client_reports')->find(
            ['organization_id' => $organizationId],
            ['sort' => ['created_at' => -1]]
        );

        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->mapRow($doc->getArrayCopy());
        }

        return $items;
    }

    public function findByOrganizationAndId(int $organizationId, int $id): ?array
    {
        $doc = $this->db->selectCollection('client_reports')->findOne([
            'organization_id' => $organizationId,
            'id' => $id,
        ]);
        if (!$doc) {
            return null;
        }

        return $this->mapRow($doc->getArrayCopy());
    }

    /**
     * @param array{client_id:int, title:string, url?:string|null, summary?:string|null, status?:string} $payload
     */
    public function create(int $organizationId, array $payload): int
    {
        $id = $this->sequence->next('client_reports');
        $now = new UTCDateTime();
        $status = (string) ($payload['status'] ?? 'published');
        if ($status !== 'published' && $status !== 'draft') {
            $status = 'published';
        }
        $publishedAt = $status === 'published' ? $now : null;

        $this->db->selectCollection('client_reports')->insertOne([
            'id' => $id,
            'organization_id' => $organizationId,
            'client_id' => (int) $payload['client_id'],
            'title' => (string) $payload['title'],
            'url' => isset($payload['url']) && $payload['url'] !== '' ? (string) $payload['url'] : null,
            'summary' => isset($payload['summary']) && $payload['summary'] !== '' ? (string) $payload['summary'] : null,
            'status' => $status,
            'published_at' => $publishedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param array{title?:string, url?:string|null, summary?:string|null, status?:string, client_id?:int} $patch
     */
    public function updateForOrganization(int $organizationId, int $id, array $patch): bool
    {
        $set = [];
        if (array_key_exists('title', $patch)) {
            $set['title'] = (string) $patch['title'];
        }
        if (array_key_exists('url', $patch)) {
            $set['url'] = $patch['url'] === '' || $patch['url'] === null ? null : (string) $patch['url'];
        }
        if (array_key_exists('summary', $patch)) {
            $set['summary'] = $patch['summary'] === '' || $patch['summary'] === null ? null : (string) $patch['summary'];
        }
        if (array_key_exists('client_id', $patch)) {
            $set['client_id'] = (int) $patch['client_id'];
        }
        if (array_key_exists('status', $patch)) {
            $st = (string) $patch['status'];
            if ($st === 'published' || $st === 'draft') {
                $set['status'] = $st;
            }
        }

        if ($set === []) {
            return false;
        }

        $set['updated_at'] = new UTCDateTime();

        if (array_key_exists('status', $set) && $set['status'] === 'published') {
            $doc = $this->db->selectCollection('client_reports')->findOne(
                ['organization_id' => $organizationId, 'id' => $id],
                ['projection' => ['published_at' => 1, 'status' => 1]]
            );
            if ($doc !== null) {
                $prev = $doc->getArrayCopy();
                $hadPublished = ($prev['published_at'] ?? null) instanceof UTCDateTime;
                $wasDraft = ($prev['status'] ?? '') === 'draft';
                if (!$hadPublished || $wasDraft) {
                    $set['published_at'] = new UTCDateTime();
                }
            }
        }

        $result = $this->db->selectCollection('client_reports')->updateOne(
            ['organization_id' => $organizationId, 'id' => $id],
            ['$set' => $set]
        );

        return $result->getMatchedCount() > 0;
    }

    public function deleteForOrganization(int $organizationId, int $id): bool
    {
        $result = $this->db->selectCollection('client_reports')->deleteOne([
            'organization_id' => $organizationId,
            'id' => $id,
        ]);

        return $result->getDeletedCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'organization_id' => (int) ($row['organization_id'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'url' => $row['url'] ?? null,
            'summary' => $row['summary'] ?? null,
            'status' => (string) ($row['status'] ?? 'published'),
            'published_at' => BsonUtil::formatDate($row['published_at'] ?? null),
            'created_at' => BsonUtil::formatDate($row['created_at'] ?? null),
            'updated_at' => BsonUtil::formatDate($row['updated_at'] ?? null),
        ];
    }
}
