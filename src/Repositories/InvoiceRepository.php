<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BsonUtil;
use App\Core\Sequence;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database as MongoDatabase;

final class InvoiceRepository
{
    public function __construct(
        private readonly MongoDatabase $db,
        private readonly Sequence $sequence
    )
    {
    }

    public function allByOrganization(int $organizationId, ?int $onlyClientId = null): array
    {
        $filter = ['organization_id' => $organizationId];
        if ($onlyClientId !== null && $onlyClientId > 0) {
            $filter['client_id'] = $onlyClientId;
        }

        $cursor = $this->db->selectCollection('invoices')->find($filter, ['sort' => ['id' => -1]]);

        $items = [];
        foreach ($cursor as $doc) {
            $row = $doc->getArrayCopy();
            $cid = isset($row['client_id']) && $row['client_id'] !== null ? (int) $row['client_id'] : null;
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'invoice_code' => (string) ($row['invoice_code'] ?? ''),
                'period' => (string) ($row['period'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'due_date' => (string) ($row['due_date'] ?? ''),
                'status' => (string) ($row['status'] ?? 'Pendente'),
                'method' => (string) ($row['method'] ?? 'Pix'),
                'client_id' => $cid !== null && $cid > 0 ? $cid : null,
                'paid_at' => BsonUtil::formatDate($row['paid_at'] ?? null),
                'created_at' => BsonUtil::formatDate($row['created_at'] ?? null),
            ];
        }

        return $items;
    }

    public function create(array $payload, int $organizationId): int
    {
        $id = $this->sequence->next('invoices');
        $now = new UTCDateTime();
        $doc = [
            'id' => $id,
            'invoice_code' => (string) $payload['invoice_code'],
            'period' => (string) $payload['period'],
            'amount' => (float) $payload['amount'],
            'due_date' => (string) $payload['due_date'],
            'status' => (string) ($payload['status'] ?? 'Pendente'),
            'method' => (string) ($payload['method'] ?? 'Pix'),
            'organization_id' => $organizationId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $cid = isset($payload['client_id']) ? (int) $payload['client_id'] : 0;
        if ($cid > 0) {
            $doc['client_id'] = $cid;
        }

        $this->db->selectCollection('invoices')->insertOne($doc);

        return $id;
    }
}
