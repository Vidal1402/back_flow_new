<?php

declare(strict_types=1);

namespace App\Core;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Database as MongoDatabase;

final class MongoSchema
{
    public static function ensureIndexes(MongoDatabase $db): void
    {
        $meta = $db->selectCollection('_meta');
        /**
         * Rodado sempre que ainda não marcou migração: deployments antigos já tinham
         * `indexes_v2` em `_meta` antes do drop do índice único (org+client), então o
         * bloco abaixo nunca executava e o Mongo continuava com E11000 ao salvar outro
         * período para o mesmo cliente → 500 no POST /api/marketing-metrics.
         */
        self::migrateMarketingMetricsLegacyUniqueIndexOnce($db, $meta);
        self::ensurePlansIndexesOnce($db, $meta);

        $schemaVersion = 'indexes_v2';
        $alreadyInitialized = $meta->findOne(['_id' => $schemaVersion]);
        if ($alreadyInitialized !== null) {
            return;
        }

        $db->selectCollection('users')->createIndex(['id' => 1], ['unique' => true]);
        $db->selectCollection('users')->createIndex(['email' => 1], ['unique' => true]);
        $db->selectCollection('clients')->createIndex(['id' => 1], ['unique' => true]);
        $db->selectCollection('clients')->createIndex(['organization_id' => 1]);
        $db->selectCollection('tasks')->createIndex(['id' => 1], ['unique' => true]);
        $db->selectCollection('tasks')->createIndex(['organization_id' => 1]);
        $db->selectCollection('tasks')->createIndex(['owner_id' => 1]);
        $db->selectCollection('invoices')->createIndex(['id' => 1], ['unique' => true]);
        $db->selectCollection('invoices')->createIndex(['organization_id' => 1]);
        $db->selectCollection('invoices')->createIndex(['invoice_code' => 1], ['unique' => true]);
        $db->selectCollection('client_reports')->createIndex(['id' => 1], ['unique' => true]);
        $db->selectCollection('client_reports')->createIndex(['organization_id' => 1, 'client_id' => 1]);

        $metrics = $db->selectCollection('marketing_metrics');
        // Migração de índice legado que impedia múltiplos períodos por cliente.
        self::dropLegacyUniqueClientIndex($metrics);
        $metrics->createIndex(['id' => 1], ['unique' => true]);
        $metrics->createIndex(['organization_id' => 1, 'client_id' => 1]);
        $metrics->createIndex(['organization_id' => 1, 'client_id' => 1, 'period_label_norm' => 1]);

        $meta->updateOne(
            ['_id' => $schemaVersion],
            ['$set' => ['initialized_at' => new UTCDateTime()]],
            ['upsert' => true]
        );
    }

    private static function dropLegacyUniqueClientIndex(\MongoDB\Collection $collection): void
    {
        try {
            $indexes = $collection->listIndexes();
            foreach ($indexes as $index) {
                $name = (string) ($index->getName() ?? '');
                $isUnique = (bool) ($index->isUnique() ?? false);
                $key = $index->getKey();
                $isOrgClientOnly =
                    isset($key['organization_id'], $key['client_id']) &&
                    count($key) === 2;
                if (!$isUnique || !$isOrgClientOnly) {
                    continue;
                }
                $collection->dropIndex($name);
                return;
            }
        } catch (\Throwable) {
            // Melhor esforço: não interrompe bootstrap se índice não existir.
        }
    }

    private static function ensurePlansIndexesOnce(MongoDatabase $db, \MongoDB\Collection $meta): void
    {
        $migId = 'mig_plans_indexes_v1';
        if ($meta->findOne(['_id' => $migId]) !== null) {
            return;
        }

        $plans = $db->selectCollection('plans');
        try {
            $plans->createIndex(['id' => 1], ['unique' => true]);
            $plans->createIndex(['organization_id' => 1, 'sort_order' => 1]);
            $plans->createIndex(['organization_id' => 1, 'slug' => 1], ['unique' => true]);
        } catch (\Throwable) {
            // Índices já podem existir.
        }

        $meta->updateOne(
            ['_id' => $migId],
            ['$set' => ['applied_at' => new UTCDateTime()]],
            ['upsert' => true]
        );
    }

    private static function migrateMarketingMetricsLegacyUniqueIndexOnce(MongoDatabase $db, \MongoDB\Collection $meta): void
    {
        $migId = 'mig_marketing_metrics_drop_unique_org_client_v1';
        if ($meta->findOne(['_id' => $migId]) !== null) {
            return;
        }

        $metrics = $db->selectCollection('marketing_metrics');
        self::dropLegacyUniqueClientIndex($metrics);
        try {
            $metrics->createIndex(['organization_id' => 1, 'client_id' => 1, 'period_label_norm' => 1]);
        } catch (\Throwable) {
            // Índice já pode existir; ignora.
        }

        $meta->updateOne(
            ['_id' => $migId],
            ['$set' => ['applied_at' => new UTCDateTime()]],
            ['upsert' => true]
        );
    }
}
