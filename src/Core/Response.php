<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /**
     * Cabeçalhos CORS. Use CORS_ORIGINS (lista separada por vírgula) em produção;
     * se vazio, permite qualquer origem com * (adequado a JWT no header, sem cookies).
     */
    public static function applyCors(): void
    {
        $raw = Env::get('CORS_ORIGINS', '') ?? '';
        $raw = trim((string) $raw);
        $allowed = array_values(
            array_filter(
                array_map(static fn (string $o): string => trim($o), explode(',', $raw)),
                static fn (string $o): bool => $o !== ''
            )
        );
        $origin = self::requestOrigin();

        if ($allowed === []) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && self::originMatchesAllowlist($origin, $allowed)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * @param array<int, string> $allowed
     */
    private static function originMatchesAllowlist(string $origin, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            if ($entry === '*' || strcasecmp($origin, $entry) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function requestOrigin(): string
    {
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            return trim((string) $_SERVER['HTTP_ORIGIN']);
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string) $name, 'Origin') === 0) {
                        return trim((string) $value);
                    }
                }
            }
        }

        return '';
    }

    public static function json(array $data, int $status = 200): void
    {
        self::applyCors();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
