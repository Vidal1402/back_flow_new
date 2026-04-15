<?php

declare(strict_types=1);

namespace App\Core;

use MongoDB\Client;
use MongoDB\Database as MongoDatabase;
use Throwable;

final class Database
{
    private static ?Client $client = null;

    private static ?MongoDatabase $db = null;

    public static function client(): Client
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $uri = Env::get('MONGODB_URI', '');
        if ($uri === '') {
            Response::json([
                'error' => 'config_error',
                'message' => 'MONGODB_URI não configurada',
            ], 500);
        }

        try {
            self::$client = new Client($uri, [
                // Evita esperas longas (10s+) quando há problema de rede/DNS com Atlas.
                'connectTimeoutMS' => 3000,
                'serverSelectionTimeoutMS' => 3000,
            ]);
        } catch (Throwable $e) {
            Response::json([
                'error' => 'db_connection_error',
                'message' => $e->getMessage(),
            ], 500);
        }

        return self::$client;
    }

    public static function database(): MongoDatabase
    {
        if (self::$db !== null) {
            return self::$db;
        }

        $name = Env::get('MONGODB_DATABASE', '');
        if ($name === '') {
            $name = self::databaseNameFromUri((string) Env::get('MONGODB_URI', '')) ?? 'united_flow';
        }

        self::$db = self::client()->selectDatabase($name);
        return self::$db;
    }

    private static function databaseNameFromUri(string $uri): ?string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        return trim($path, '/');
    }
}
