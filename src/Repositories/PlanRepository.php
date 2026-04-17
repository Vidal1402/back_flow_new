<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BsonUtil;
use App\Core\Sequence;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database as MongoDatabase;

final class PlanRepository
{
    public function __construct(
        private readonly MongoDatabase $db,
        private readonly Sequence $sequence
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allByOrganization(int $organizationId, bool $activeOnly = false): array
    {
        $filter = ['organization_id' => $organizationId];
        if ($activeOnly) {
            $filter['is_active'] = true;
        }

        $cursor = $this->db->selectCollection('plans')->find($filter, ['sort' => ['sort_order' => 1, 'id' => 1]]);

        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->mapRow($doc->getArrayCopy());
        }

        return $items;
    }

    public function findByOrganizationAndId(int $organizationId, int $id): ?array
    {
        $doc = $this->db->selectCollection('plans')->findOne([
            'organization_id' => $organizationId,
            'id' => $id,
        ]);

        return $doc ? $this->mapRow($doc->getArrayCopy()) : null;
    }

    /**
     * @param array{name:string, slug?:string, description?:string|null, price?:float|int, billing_cycle?:string, features?:list<string>, is_active?:bool, sort_order?:int} $payload
     */
    public function create(int $organizationId, array $payload): int
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name');
        }

        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->uniqueSlug($organizationId, $this->slugify($name));
        } else {
            $slug = $this->slugify($slug);
            if ($this->findByOrganizationAndSlug($organizationId, $slug) !== null) {
                throw new \InvalidArgumentException('slug_exists');
            }
        }

        $id = $this->sequence->next('plans');
        $now = new UTCDateTime();
        $features = $this->normalizeFeatures($payload['features'] ?? []);

        $this->db->selectCollection('plans')->insertOne([
            'id' => $id,
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => $slug,
            'description' => isset($payload['description']) ? trim((string) $payload['description']) : '',
            'price' => isset($payload['price']) ? (float) $payload['price'] : 0.0,
            'billing_cycle' => trim((string) ($payload['billing_cycle'] ?? 'monthly')) ?: 'monthly',
            'features' => $features,
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            'sort_order' => isset($payload['sort_order']) ? (int) $payload['sort_order'] : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param array{name?:string, slug?:string, description?:string|null, price?:float|int, billing_cycle?:string, features?:list<string>, is_active?:bool, sort_order?:int} $payload
     */
    public function updateForOrganization(int $organizationId, int $id, array $payload): bool
    {
        $current = $this->rawByOrganizationAndId($organizationId, $id);
        if ($current === null) {
            return false;
        }

        $set = [];
        if (array_key_exists('name', $payload)) {
            $set['name'] = trim((string) $payload['name']);
        }
        if (array_key_exists('description', $payload)) {
            $set['description'] = $payload['description'] === null ? '' : trim((string) $payload['description']);
        }
        if (array_key_exists('price', $payload)) {
            $set['price'] = (float) $payload['price'];
        }
        if (array_key_exists('billing_cycle', $payload)) {
            $set['billing_cycle'] = trim((string) $payload['billing_cycle']) ?: 'monthly';
        }
        if (array_key_exists('features', $payload)) {
            $set['features'] = $this->normalizeFeatures($payload['features'] ?? []);
        }
        if (array_key_exists('is_active', $payload)) {
            $set['is_active'] = (bool) $payload['is_active'];
        }
        if (array_key_exists('sort_order', $payload)) {
            $set['sort_order'] = (int) $payload['sort_order'];
        }
        if (array_key_exists('slug', $payload)) {
            $newSlug = trim((string) $payload['slug']);
            if ($newSlug !== '') {
                $slugNorm = $this->slugify($newSlug);
                $other = $this->findByOrganizationAndSlug($organizationId, $slugNorm);
                if ($other !== null && (int) ($other['id'] ?? 0) !== $id) {
                    throw new \InvalidArgumentException('slug_exists');
                }
                $set['slug'] = $slugNorm;
            }
        }

        if ($set === []) {
            return false;
        }

        $set['updated_at'] = new UTCDateTime();
        $result = $this->db->selectCollection('plans')->updateOne(
            ['organization_id' => $organizationId, 'id' => $id],
            ['$set' => $set]
        );

        return $result->getMatchedCount() > 0;
    }

    public function deleteForOrganization(int $organizationId, int $id): bool
    {
        $result = $this->db->selectCollection('plans')->deleteOne([
            'organization_id' => $organizationId,
            'id' => $id,
        ]);

        return $result->getDeletedCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByOrganizationAndSlug(int $organizationId, string $slug): ?array
    {
        $doc = $this->db->selectCollection('plans')->findOne([
            'organization_id' => $organizationId,
            'slug' => $slug,
        ]);

        return $doc ? $doc->getArrayCopy() : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawByOrganizationAndId(int $organizationId, int $id): ?array
    {
        $doc = $this->db->selectCollection('plans')->findOne([
            'organization_id' => $organizationId,
            'id' => $id,
        ]);

        return $doc ? $doc->getArrayCopy() : null;
    }

    private function uniqueSlug(int $organizationId, string $base): string
    {
        $slug = $base;
        $n = 1;
        while ($this->findByOrganizationAndSlug($organizationId, $slug) !== null) {
            $n++;
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    private function slugify(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
        $s = trim((string) $s, '-');

        return $s !== '' ? $s : 'plano';
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeFeatures(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $line) {
            $t = trim((string) $line);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $features = $row['features'] ?? [];
        if (!is_array($features)) {
            $features = [];
        }
        $featList = [];
        foreach ($features as $f) {
            $featList[] = (string) $f;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'organization_id' => (int) ($row['organization_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'price' => (float) ($row['price'] ?? 0),
            'billing_cycle' => (string) ($row['billing_cycle'] ?? 'monthly'),
            'features' => $featList,
            'is_active' => (bool) ($row['is_active'] ?? true),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'created_at' => BsonUtil::formatDate($row['created_at'] ?? null),
            'updated_at' => BsonUtil::formatDate($row['updated_at'] ?? null),
        ];
    }
}
