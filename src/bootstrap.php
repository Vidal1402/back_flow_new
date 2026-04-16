<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\HealthController;
use App\Controllers\InvoiceController;
use App\Controllers\TaskController;
use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Repositories\ClientRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;

Env::load(dirname(__DIR__) . '/.env');

if (method_exists(Database::class, 'connection')) {
    /** @var \PDO $pdo */
    $pdo = Database::connection();
} elseif (method_exists(Database::class, 'getConnection')) {
    /** @var \PDO $pdo */
    $pdo = Database::getConnection();
} elseif (method_exists(Database::class, 'connect')) {
    /** @var \PDO $pdo */
    $pdo = Database::connect();
} elseif (method_exists(Database::class, 'pdo')) {
    /** @var \PDO $pdo */
    $pdo = Database::pdo();
} else {
    // Fallback para manter compatibilidade com versões antigas/alternativas da classe Database.
    $dsn = Env::get('DB_DSN', 'pgsql:host=localhost;port=5432;dbname=postgres');
    $user = Env::get('DB_USER', '') ?: null;
    $pass = Env::get('DB_PASS', '') ?: null;

    try {
        $pdo = new \PDO((string) $dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    } catch (\PDOException $e) {
        Response::json([
            'error' => 'db_connection_error',
            'message' => $e->getMessage(),
        ], 500);
    }
}

Migrator::run($pdo, dirname(__DIR__) . '/database/migrations');

$users = new UserRepository($pdo);
$clients = new ClientRepository($pdo);
$tasks = new TaskRepository($pdo);
$invoices = new InvoiceRepository($pdo);

$authController = new AuthController($users);
$clientController = new ClientController($clients);
$taskController = new TaskController($tasks);
$invoiceController = new InvoiceController($invoices);
$healthController = new HealthController();

$authMiddleware = new AuthMiddleware($users);
$adminOnly = function (Request $request, array $params, array $context): array {
    $role = $context['user']['role'] ?? '';
    if ($role !== 'admin') {
        Response::json(['error' => 'forbidden', 'message' => 'Acesso restrito para admin'], 403);
    }
    return [];
};

$router = new Router();

$router->add('GET', '/api/health', fn(Request $request) => $healthController($request));

$router->add('POST', '/api/auth/register', fn(Request $request) => $authController->register($request));
$router->add('POST', '/api/auth/login', fn(Request $request) => $authController->login($request));
$router->add('GET', '/api/auth/me', fn(Request $request, array $params, array $context) => $authController->me($context), [$authMiddleware]);
$router->add('POST', '/api/admin/users', fn(Request $request, array $params, array $context) => $authController->adminCreateUser($request, $context), [$authMiddleware, $adminOnly]);

$router->add('GET', '/api/clients', fn(Request $request, array $params, array $context) => $clientController->index($context), [$authMiddleware]);
$router->add('POST', '/api/clients', fn(Request $request, array $params, array $context) => $clientController->store($request, $context), [$authMiddleware, $adminOnly]);

$router->add('GET', '/api/tasks', fn(Request $request, array $params, array $context) => $taskController->index($context), [$authMiddleware]);
$router->add('POST', '/api/tasks', fn(Request $request, array $params, array $context) => $taskController->store($request, $context), [$authMiddleware]);
$router->add('PATCH', '/api/tasks/{id}/status', fn(Request $request, array $params, array $context) => $taskController->updateStatus($request, $params, $context), [$authMiddleware]);

$router->add('GET', '/api/invoices', fn(Request $request, array $params, array $context) => $invoiceController->index($context), [$authMiddleware]);
$router->add('POST', '/api/invoices', fn(Request $request, array $params, array $context) => $invoiceController->store($request, $context), [$authMiddleware, $adminOnly]);

return $router;
