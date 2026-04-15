<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static bool $loaded = false;

    public static function load(string $filePath): void
    {
        if (self::$loaded || !is_file($filePath)) {
            self::$loaded = true;
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null) {
            return (string) $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER)) {
            return (string) $_SERVER[$key];
        }
        $fromEnv = getenv($key);
        if ($fromEnv !== false) {
            return (string) $fromEnv;
        }

        return $default;
    }
}
