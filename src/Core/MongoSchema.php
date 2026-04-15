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
        $schemaVersion = 'indexes_v1';
        $alreadyInitialized = $meta->findOne(['_id' => $schemaVersion]);
        if ($alreadyInitialized !== null) {
            return;
        }

        $db->selectCollection('users')->createIndex(['email' => 1], ['unique' => true]);
        $db->selectCollection('clients')->createIndex(['organization_id' => 1]);
        $db->selectCollection('tasks')->createIndex(['organization_id' => 1]);
        $db->selectCollection('tasks')->createIndex(['owner_id' => 1]);
        $db->selectCollection('invoices')->createIndex(['organization_id' => 1]);
        $db->selectCollection('invoices')->createIndex(['invoice_code' => 1], ['unique' => true]);

        $meta->updateOne(
            ['_id' => $schemaVersion],
            ['$set' => ['initialized_at' => new UTCDateTime()]],
            ['upsert' => true]
        );
    }
}
