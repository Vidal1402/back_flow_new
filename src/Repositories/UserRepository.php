<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => mb_strtolower($email)]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email, role, organization_id, created_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function create(string $name, string $email, string $passwordHash, string $role = 'colaborador', int $organizationId = 1): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, organization_id) VALUES (:name, :email, :password_hash, :role, :organization_id)'
        );
        $stmt->execute([
            'name' => $name,
            'email' => mb_strtolower($email),
            'password_hash' => $passwordHash,
            'role' => $role,
            'organization_id' => $organizationId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
