<?php

declare(strict_types=1);

namespace App\Core;

use MongoDB\Client;
use MongoDB\Database as MongoDatabase;
use RuntimeException;
use Throwable;

final class MongoConnection
{
    private static ?MongoDatabase $db = null;

    /**
     * Atlas / drivers costumam funcionar melhor com retryWrites + majority no query string.
     */
    private static function normalizeUri(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return $uri;
        }
        if (stripos($uri, 'retrywrites=') !== false) {
            return $uri;
        }
        $sep = str_contains($uri, '?') ? '&' : '?';

        return $uri . $sep . 'retryWrites=true&w=majority';
    }

    /**
     * Conecta e faz ping. Em falha, lança exceção (útil para /api/diagnostic).
     *
     * @throws Throwable
     */
    private static function connect(): MongoDatabase
    {
        if (!class_exists(Client::class)) {
            throw new RuntimeException('Biblioteca mongodb/mongodb não instalada. Rode composer install no build.');
        }

        $rawUri = trim((string) (Env::get('MONGODB_URI') ?? Env::get('MONGO_URL') ?? ''));
        if ($rawUri === '') {
            throw new RuntimeException('MONGODB_URI não configurado no ambiente.');
        }

        $uri = self::normalizeUri($rawUri);

        $dbName = trim((string) (Env::get('MONGODB_DATABASE') ?? ''));
        if ($dbName === '') {
            $parsed = parse_url($rawUri, PHP_URL_PATH);
            $parsed = is_string($parsed) ? trim($parsed, '/') : '';
            $dbName = $parsed !== '' ? $parsed : 'united_flow';
        }

        $client = new Client($uri);
        $database = $client->selectDatabase($dbName);
        $database->command(['ping' => 1]);

        self::$db = $database;

        return self::$db;
    }

    public static function database(): MongoDatabase
    {
        if (self::$db !== null) {
            return self::$db;
        }

        try {
            return self::connect();
        } catch (Throwable $e) {
            $hint = '';
            $msg = $e->getMessage();
            if (stripos($msg, 'No suitable servers') !== false || stripos($msg, 'connection') !== false) {
                $hint = ' Verifique no MongoDB Atlas → Network Access se o IP do Railway está liberado (ex.: 0.0.0.0/0 para testes).';
            }
            Response::json([
                'error' => 'db_connection_error',
                'message' => $msg . $hint,
            ], 500);
        }
    }

    /**
     * Diagnóstico sem encerrar o processo — para GET /api/diagnostic.
     *
     * @return array<string, mixed>
     */
    public static function diagnosticPing(): array
    {
        try {
            if (self::$db === null) {
                self::connect();
            } else {
                self::$db->command(['ping' => 1]);
            }

            $dbName = trim((string) (Env::get('MONGODB_DATABASE') ?? ''));
            if ($dbName === '') {
                $rawUri = trim((string) (Env::get('MONGODB_URI') ?? Env::get('MONGO_URL') ?? ''));
                $parsed = parse_url($rawUri, PHP_URL_PATH);
                $parsed = is_string($parsed) ? trim($parsed, '/') : '';
                $dbName = $parsed !== '' ? $parsed : 'united_flow';
            }

            return [
                'ok' => true,
                'mongodb' => 'connected',
                'database' => $dbName,
                'ext_mongodb' => extension_loaded('mongodb') ? 'yes' : 'no',
            ];
        } catch (Throwable $e) {
            $hint = 'Atlas → Network Access: libere 0.0.0.0/0 ou o IP de saída do Railway.';
            $msg = $e->getMessage();
            if (stripos($msg, 'No suitable servers') !== false || stripos($msg, 'connection') !== false) {
                $msg .= ' (' . $hint . ')';
            }

            return [
                'ok' => false,
                'mongodb' => 'error',
                'message' => $msg,
                'ext_mongodb' => extension_loaded('mongodb') ? 'yes' : 'no',
            ];
        }
    }
}
