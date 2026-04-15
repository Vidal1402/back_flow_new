<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\HealthController;
use App\Controllers\InvoiceController;
use App\Controllers\TaskController;
use App\Core\Database;
use App\Core\Env;
use App\Core\MongoSchema;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Sequence;
use App\Middleware\AuthMiddleware;
use App\Repositories\ClientRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;

Env::load(dirname(__DIR__) . '/.env');

$db = Database::database();
MongoSchema::ensureIndexes($db);

$sequence = new Sequence($db);

$users = new UserRepository($db, $sequence);
$clients = new ClientRepository($db, $sequence);
$tasks = new TaskRepository($db, $sequence);
$invoices = new InvoiceRepository($db);

// Seed opcional de admin para primeiro acesso em produção.
$bootstrapAdminEmail = trim((string) (Env::get('ADMIN_EMAIL', '') ?? ''));
$bootstrapAdminPassword = (string) (Env::get('ADMIN_PASSWORD', '') ?? '');
if ($bootstrapAdminEmail !== '' && $bootstrapAdminPassword !== '' && !$users->findByEmail($bootstrapAdminEmail)) {
    $bootstrapAdminName = trim((string) (Env::get('ADMIN_NAME', 'Administrador') ?? 'Administrador'));
    $users->create(
        $bootstrapAdminName !== '' ? $bootstrapAdminName : 'Administrador',
        $bootstrapAdminEmail,
        password_hash($bootstrapAdminPassword, PASSWORD_BCRYPT),
        'admin',
        1
    );
}

// Opcional: reset forçado de senha/admin para destravar acesso em produção.
// Use ADMIN_FORCE_RESET_PASSWORD=true temporariamente; depois volte para false.
$forceResetFlagRaw = mb_strtolower(trim((string) (Env::get('ADMIN_FORCE_RESET_PASSWORD', 'false') ?? 'false')));
$forceResetAdminPassword = in_array($forceResetFlagRaw, ['1', 'true', 'yes', 'on'], true);
if ($bootstrapAdminEmail !== '' && $bootstrapAdminPassword !== '' && $forceResetAdminPassword) {
    $bootstrapAdminName = trim((string) (Env::get('ADMIN_NAME', 'Administrador') ?? 'Administrador'));
    $existingAdmin = $users->findByEmail($bootstrapAdminEmail);
    if ($existingAdmin === null) {
        $users->create(
            $bootstrapAdminName !== '' ? $bootstrapAdminName : 'Administrador',
            $bootstrapAdminEmail,
            password_hash($bootstrapAdminPassword, PASSWORD_BCRYPT),
            'admin',
            1
        );
    } else {
        $nameTarget = $bootstrapAdminName !== '' ? $bootstrapAdminName : 'Administrador';
        $roleCurrent = (string) ($existingAdmin['role'] ?? '');
        $orgCurrent = (int) ($existingAdmin['organization_id'] ?? 0);
        $hashCurrent = (string) ($existingAdmin['password_hash'] ?? '');
        $passwordAlreadyValid = $hashCurrent !== '' && password_verify($bootstrapAdminPassword, $hashCurrent);
        $nameCurrent = (string) ($existingAdmin['name'] ?? '');

        if (!$passwordAlreadyValid || $roleCurrent !== 'admin' || $orgCurrent !== 1 || $nameCurrent !== $nameTarget) {
            $db->selectCollection('users')->updateOne(
                ['_id' => (int) $existingAdmin['id']],
                [
                    '$set' => [
                        'name' => $nameTarget,
                        'password_hash' => password_hash($bootstrapAdminPassword, PASSWORD_BCRYPT),
                        'role' => 'admin',
                        'organization_id' => 1,
                    ],
                ]
            );
        }
    }
}

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

return $router;
