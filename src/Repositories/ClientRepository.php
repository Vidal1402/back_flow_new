<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ClientRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function allByOrganization(int $organizationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE organization_id = :org ORDER BY id DESC');
        $stmt->execute(['org' => $organizationId]);
        return $stmt->fetchAll() ?: [];
    }

    public function create(array $payload, int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clients (name, empresa, email, telefone, plano, valor, status, organization_id)
             VALUES (:name, :empresa, :email, :telefone, :plano, :valor, :status, :organization_id)'
        );
        $stmt->execute([
            'name' => $payload['name'],
            'empresa' => $payload['empresa'],
            'email' => $payload['email'],
            'telefone' => $payload['telefone'] ?? null,
            'plano' => $payload['plano'] ?? 'Growth',
            'valor' => $payload['valor'] ?? 0,
            'status' => $payload['status'] ?? 'ativo',
            'organization_id' => $organizationId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
