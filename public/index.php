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

@ini_set('display_errors', '0');

$showDebugDetails = (static function (): bool {
    $d = strtolower(trim((string) Env::get('DEBUG', '')));
    return Env::get('APP_ENV', 'production') === 'local' || in_array($d, ['1', 'true', 'yes'], true);
})();

// Erros fatais viram JSON (se nenhum header foi enviado). Não chame applyCors() antes do bootstrap,
// senão headers_sent() impede este handler e o PHP devolve HTML que quebra o fetch no front.
register_shutdown_function(static function () use ($showDebugDetails): void {
    $e = error_get_last();
    if ($e === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($e['type'], $fatalTypes, true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    if (!class_exists(Response::class)) {
        return;
    }

    // Em /api/diagnostic, sempre mostre detalhes (para depurar rapidamente no Railway).
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH);
    $forceDetails = is_string($path) && $path === '/api/diagnostic';

    Response::json([
        'error' => 'fatal_error',
        'message' => 'Erro fatal no servidor (PHP).',
        'details' => ($showDebugDetails || $forceDetails) ? ($e['message'] ?? '') : null,
        'file' => ($showDebugDetails || $forceDetails) ? ($e['file'] ?? null) : null,
        'line' => ($showDebugDetails || $forceDetails) ? (int) ($e['line'] ?? 0) : null,
    ], 500);
});

// Preflight CORS antes do bootstrap (DB), senão o navegador pode não receber os headers.
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    Response::applyCors();
    http_response_code(204);
    exit;
}

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

// Diagnóstico Mongo **sem** carregar o bootstrap completo (índices, seed, rotas).
if ($request->method === 'GET' && $path === '/api/diagnostic') {
    $out = \App\Core\MongoConnection::diagnosticPing();
    $code = !empty($out['ok']) ? 200 : 503;
    Response::json($out, $code);
}

try {
    $router = require __DIR__ . '/../src/bootstrap.php';
    $router->dispatch($request);
} catch (\Throwable $e) {
    Response::json([
        'error' => 'server_error',
        'message' => 'Erro interno no servidor.',
        'details' => $showDebugDetails ? $e->getMessage() : null,
    ], 500);
}
