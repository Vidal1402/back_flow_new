<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BsonUtil;
use App\Core\Sequence;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database as MongoDatabase;

final class UserRepository
{
    public function __construct(
        private readonly MongoDatabase $db,
        private readonly Sequence $sequence,
    ) {
    }

    public function findByEmail(string $email): ?array
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $doc = $this->db->selectCollection('users')->findOne(['email' => $normalizedEmail]);
        if ($doc === null) {
            // Fallback para registros legados com caixa diferente.
            $doc = $this->db->selectCollection('users')->findOne([
                'email' => [
                    '$regex' => '^' . preg_quote($normalizedEmail, '/') . '$',
                    '$options' => 'i',
                ],
            ]);
        }
        if ($doc === null) {
            return null;
        }

        $row = $this->normalizeAssoc($doc);
        $row['id'] = (int) $row['_id'];
        unset($row['_id']);

        return $row;
    }

    public function findById(int $id): ?array
    {
        $doc = $this->db->selectCollection('users')->findOne(
            ['_id' => $id],
            [
                'projection' => [
                    'password_hash' => 0,
                ],
            ]
        );
        if ($doc === null) {
            return null;
        }

        return $this->toUserRow($this->normalizeAssoc($doc));
    }

    public function create(string $name, string $email, string $passwordHash, string $role = 'colaborador', int $organizationId = 1): int
    {
        $id = $this->sequence->next('users');
        $now = new UTCDateTime();

        $this->db->selectCollection('users')->insertOne([
            '_id' => $id,
            'name' => $name,
            'email' => mb_strtolower($email),
            'password_hash' => $passwordHash,
            'role' => $role,
            'organization_id' => $organizationId,
            'created_at' => $now,
        ]);

        return $id;
    }

    public function ensureAdminCredentials(string $email, string $name, string $plainPassword, int $organizationId = 1): int
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $existing = $this->findByEmail($normalizedEmail);
        if ($existing === null) {
            return $this->create($name, $normalizedEmail, password_hash($plainPassword, PASSWORD_BCRYPT), 'admin', $organizationId);
        }

        $currentHash = (string) ($existing['password_hash'] ?? '');
        $passwordAlreadyValid = $currentHash !== '' && password_verify($plainPassword, $currentHash);
        $needsUpdate =
            ((string) ($existing['name'] ?? '')) !== $name ||
            ((string) ($existing['email'] ?? '')) !== $normalizedEmail ||
            ((string) ($existing['role'] ?? '')) !== 'admin' ||
            ((int) ($existing['organization_id'] ?? 0)) !== $organizationId ||
            !$passwordAlreadyValid;

        if ($needsUpdate) {
            $this->db->selectCollection('users')->updateOne(
                ['_id' => (int) $existing['id']],
                [
                    '$set' => [
                        'name' => $name,
                        'email' => $normalizedEmail,
                        'password_hash' => password_hash($plainPassword, PASSWORD_BCRYPT),
                        'role' => 'admin',
                        'organization_id' => $organizationId,
                    ],
                ]
            );
        }

        return (int) $existing['id'];
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function toUserRow(array $doc): array
    {
        return [
            'id' => (int) $doc['_id'],
            'name' => (string) $doc['name'],
            'email' => (string) $doc['email'],
            'role' => (string) $doc['role'],
            'organization_id' => (int) $doc['organization_id'],
            'created_at' => BsonUtil::formatDate($doc['created_at'] ?? null) ?? '',
        ];
    }

    /**
     * @param iterable<mixed, mixed> $doc
     * @return array<string, mixed>
     */
    private function normalizeAssoc(iterable $doc): array
    {
        $out = [];
        foreach ($doc as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
