<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Migrator
{
    public static function run(PDO $pdo, string $migrationsDir): void
    {
        if (!is_dir($migrationsDir)) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                filename TEXT PRIMARY KEY,
                executed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $files = glob(rtrim($migrationsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql');
        if (!is_array($files)) {
            return;
        }

        sort($files);

        foreach ($files as $file) {
            $filename = basename($file);
            $stmt = $pdo->prepare('SELECT filename FROM schema_migrations WHERE filename = :filename LIMIT 1');
            $stmt->execute(['filename' => $filename]);
            if ($stmt->fetch()) {
                continue;
            }

            $sql = file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                continue;
            }

            $pdo->beginTransaction();
            $pdo->exec($sql);
            $insert = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (:filename)');
            $insert->execute(['filename' => $filename]);
            $pdo->commit();
        }
    }
}
