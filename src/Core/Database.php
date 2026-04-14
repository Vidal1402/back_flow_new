<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = Env::get('DB_DSN', 'pgsql:host=localhost;port=5432;dbname=postgres');
        $user = Env::get('DB_USER', '') ?: null;
        $pass = Env::get('DB_PASS', '') ?: null;

        if (str_starts_with($dsn, 'sqlite:')) {
            $sqlitePath = substr($dsn, 7);
            if ($sqlitePath !== '') {
                $absolutePath = self::toAbsolutePath($sqlitePath);
                $dir = dirname($absolutePath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $dsn = 'sqlite:' . $absolutePath;
            }
        }

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            Response::json([
                'error' => 'db_connection_error',
                'message' => $e->getMessage(),
            ], 500);
        }

        return self::$pdo;
    }

    private static function toAbsolutePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $path;
    }
}
