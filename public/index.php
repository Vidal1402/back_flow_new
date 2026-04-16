<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

Env::load(dirname(__DIR__) . '/.env');

// Preflight CORS antes do bootstrap (DB/migrações), senão o navegador pode não receber os headers.
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    Response::applyCors();
    http_response_code(204);
    exit;
}

// Envia CORS o mais cedo possível também para GET/POST, antes do bootstrap (evita resposta 200 sem cabeçalho).
Response::applyCors();

$request = Request::capture();
$path = rtrim($request->path, '/') ?: '/';

// Health sem conexao com banco (Railway / load balancer / probes)
if ($request->method === 'GET' && in_array($path, ['/', '/api/health', '/health'], true)) {
    Response::json([
        'status' => 'ok',
        'service' => 'php-mvp-api',
        'timestamp' => date(DATE_ATOM),
    ]);
}

try {
    $router = require __DIR__ . '/../src/bootstrap.php';
    $router->dispatch($request);
} catch (\Throwable $e) {
    Response::json([
        'error' => 'server_error',
        'message' => 'Erro interno no servidor.',
        'details' => Env::get('APP_ENV', 'production') === 'local' ? $e->getMessage() : null,
    ], 500);
}
