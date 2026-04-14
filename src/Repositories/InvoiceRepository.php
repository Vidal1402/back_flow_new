<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class InvoiceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function allByOrganization(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, invoice_code, period, amount, due_date, status, method, paid_at, created_at
             FROM invoices
             WHERE organization_id = :org
             ORDER BY id DESC'
        );
        $stmt->execute(['org' => $organizationId]);
        return $stmt->fetchAll() ?: [];
    }
}
