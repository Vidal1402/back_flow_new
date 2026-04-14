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
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = self::getAllHeadersSafe();
        $query = $_GET ?? [];
        $body = self::parseBody($headers);

        return new self($method, $path, $headers, $query, $body);
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
            return is_array($headers) ? $headers : [];
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = str_replace('_', '-', strtolower(substr($name, 5)));
                $headers[ucwords($key, '-')] = (string) $value;
            }
        }
        return $headers;
    }
}
