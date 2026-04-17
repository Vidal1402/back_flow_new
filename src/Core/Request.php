<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $body
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $path = self::normalizePath($path);
        $headers = self::getAllHeadersSafe();
        $query = $_GET ?? [];
        $body = self::parseBody($headers);

        return new self($method, $path, $headers, $query, $body);
    }

    /**
     * Garante o mesmo formato das rotas registradas (ex.: sem barra final, sem //).
     * Evita 404 "Rota não encontrada" em proxies / clientes que acrescentam "/" ou duplicam segmentos.
     */
    private static function normalizePath(string $path): string
    {
        $path = rawurldecode(str_replace('\\', '/', $path));
        $collapsed = preg_replace('#/+#', '/', $path);
        $path = is_string($collapsed) && $collapsed !== '' ? $collapsed : '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    public function header(string $key): ?string
    {
        $lookup = strtolower($key);
        foreach ($this->headers as $name => $value) {
            if (strtolower($name) === $lookup) {
                return $value;
            }
        }
        return null;
    }

    private static function parseBody(array $headers): array
    {
        $contentType = '';
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $contentType = strtolower($value);
                break;
            }
        }

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (!is_string($raw) || trim($raw) === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return $_POST ?? [];
    }

    private static function getAllHeadersSafe(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $headers = is_array($headers) ? $headers : [];
            return self::ensureAuthorizationHeader($headers);
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = str_replace('_', '-', strtolower(substr($name, 5)));
                $headers[ucwords($key, '-')] = (string) $value;
            }
        }
        return self::ensureAuthorizationHeader($headers);
    }

    private static function ensureAuthorizationHeader(array $headers): array
    {
        foreach ($headers as $name => $_) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                return $headers;
            }
        }

        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                $headers['Authorization'] = trim($value);
                break;
            }
        }

        return $headers;
    }
}
