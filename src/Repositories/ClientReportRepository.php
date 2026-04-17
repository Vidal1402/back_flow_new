<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BsonUtil;
use App\Core\Sequence;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database as MongoDatabase;

final class ClientReportRepository
{
    /** Tamanho máximo por arquivo (bytes), após decode base64. */
    public const MAX_ATTACHMENT_BYTES = 3 * 1024 * 1024;

    /** Quantidade máxima de arquivos por relatório. */
    public const MAX_ATTACHMENTS = 10;

    /** Tamanho total máximo dos binários em um documento (margem ao limite 16MB do BSON). */
    public const MAX_TOTAL_ATTACHMENT_BYTES = 14 * 1024 * 1024;

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
            $items[] = $this->mapRow($doc->getArrayCopy(), false);
        }

        return $items;
    }

    public function allByOrganizationAndClient(int $organizationId, int $clientId): array
    {
        $cursor = $this->db->selectCollection('client_reports')->find(
            ['organization_id' => $organizationId, 'client_id' => $clientId],
            ['sort' => ['created_at' => -1]]
        );

        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->mapRow($doc->getArrayCopy(), false);
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

        return $this->mapRow($doc->getArrayCopy(), true);
    }

    /**
     * @param array{
     *   client_id:int,
     *   title:string,
     *   url?:string|null,
     *   summary?:string|null,
     *   status?:string,
     *   attachments?: list<array{filename:string, mime_type:string, data:Binary}>
     * } $payload
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

        $attachments = $payload['attachments'] ?? [];
        $attachments = is_array($attachments) ? $this->normalizeAttachmentsForStorage($attachments) : [];

        $doc = [
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
        ];
        if ($attachments !== []) {
            $doc['attachments'] = $attachments;
        }

        $this->db->selectCollection('client_reports')->insertOne($doc);

        return $id;
    }

    /**
     * @param list<array{filename:string, mime_type:string, data:Binary}> $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeAttachmentsForStorage(array $raw): array
    {
        $out = [];
        $attachmentId = 1;
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $fn = trim((string) ($item['filename'] ?? ''));
            $mime = trim((string) ($item['mime_type'] ?? 'application/octet-stream'));
            $data = $item['data'] ?? null;
            if ($fn === '' || !$data instanceof Binary) {
                continue;
            }
            $bytes = $data->getData();
            $len = strlen($bytes);
            if ($len <= 0 || $len > self::MAX_ATTACHMENT_BYTES) {
                continue;
            }
            $out[] = [
                'attachment_id' => $attachmentId,
                'filename' => $fn,
                'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
                'size' => $len,
                'data' => new Binary($bytes, Binary::TYPE_GENERIC),
            ];
            $attachmentId++;
        }

        return $out;
    }

    /**
     * @param array{title?:string, url?:string|null, summary?:string|null, status?:string, client_id?:int, attachments?: list<array{filename:string, mime_type:string, data:Binary}>|null} $patch
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
        if (array_key_exists('attachments', $patch)) {
            $att = $patch['attachments'];
            if ($att === null) {
                $set['attachments'] = null;
            } elseif (is_array($att)) {
                $normalized = $this->normalizeAttachmentsForStorage($att);
                $set['attachments'] = $normalized === [] ? null : $normalized;
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
    private function mapRow(array $row, bool $includeAttachmentData): array
    {
        $attachmentsOut = [];
        $rawAtt = $row['attachments'] ?? null;
        if (is_array($rawAtt)) {
            foreach ($rawAtt as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $aid = (int) ($a['attachment_id'] ?? 0);
                $fn = (string) ($a['filename'] ?? '');
                $mime = (string) ($a['mime_type'] ?? 'application/octet-stream');
                $size = (int) ($a['size'] ?? 0);
                $data = $a['data'] ?? null;
                if ($size <= 0 && $data instanceof Binary) {
                    $size = strlen($data->getData());
                }
                $item = [
                    'attachment_id' => $aid > 0 ? $aid : null,
                    'filename' => $fn,
                    'mime_type' => $mime,
                    'size' => $size,
                ];
                if ($includeAttachmentData && $data instanceof Binary) {
                    $item['data_base64'] = base64_encode($data->getData());
                }
                $attachmentsOut[] = $item;
            }
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'organization_id' => (int) ($row['organization_id'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'url' => $row['url'] ?? null,
            'summary' => $row['summary'] ?? null,
            'status' => (string) ($row['status'] ?? 'published'),
            'attachments' => $attachmentsOut,
            'published_at' => BsonUtil::formatDate($row['published_at'] ?? null),
            'created_at' => BsonUtil::formatDate($row['created_at'] ?? null),
            'updated_at' => BsonUtil::formatDate($row['updated_at'] ?? null),
        ];
    }
}
