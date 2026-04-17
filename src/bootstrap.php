<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\ClientReportController;
use App\Controllers\HealthController;
use App\Controllers\InvoiceController;
use App\Controllers\MarketingMetricController;
use App\Controllers\PlanController;
use App\Controllers\TaskController;
use App\Core\Env;
use App\Core\MongoConnection;
use App\Core\MongoSchema;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Sequence;
use App\Middleware\AuthMiddleware;
use App\Repositories\ClientRepository;
use App\Repositories\ClientReportRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\MarketingMetricRepository;
use App\Repositories\PlanRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;

Env::load(dirname(__DIR__) . '/.env');

$db = MongoConnection::database();
MongoSchema::ensureIndexes($db);
$sequence = new Sequence($db);

$users = new UserRepository($db, $sequence);
$clients = new ClientRepository($db, $sequence);
$tasks = new TaskRepository($db, $sequence);
$invoices = new InvoiceRepository($db, $sequence);
$clientReports = new ClientReportRepository($db, $sequence);
$marketingMetrics = new MarketingMetricRepository($db, $sequence);
$plans = new PlanRepository($db, $sequence);

// Seed opcional de admin inicial via ambiente (idempotente).
$seedAdminEmail = mb_strtolower(trim((string) (Env::get('SEED_ADMIN_EMAIL') ?? '')));
$seedAdminPassword = (string) (Env::get('SEED_ADMIN_PASSWORD') ?? '');
$seedAdminName = trim((string) (Env::get('SEED_ADMIN_NAME') ?? 'Administrador'));
if ($seedAdminEmail !== '' && $seedAdminPassword !== '' && $users->findByEmail($seedAdminEmail) === null) {
    $users->create(
        $seedAdminName !== '' ? $seedAdminName : 'Administrador',
        $seedAdminEmail,
        password_hash($seedAdminPassword, PASSWORD_BCRYPT),
        'admin',
        1
    );
}

$authController = new AuthController($users, $clients);
$clientController = new ClientController($clients);
$clientReportController = new ClientReportController($clientReports, $clients);
$taskController = new TaskController($tasks, $clients);
$invoiceController = new InvoiceController($invoices, $clients);
$marketingMetricController = new MarketingMetricController($marketingMetrics, $clients);
$planController = new PlanController($plans);
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
$router->add('GET', '/api/clients/me', fn(Request $request, array $params, array $context) => $clientController->meForPortal($context), [$authMiddleware]);
$router->add('POST', '/api/clients', fn(Request $request, array $params, array $context) => $clientController->store($request, $context), [$authMiddleware, $adminOnly]);
$router->add('GET', '/api/clients/{id}', fn(Request $request, array $params, array $context) => $clientController->show($params, $context), [$authMiddleware]);
$router->add('PATCH', '/api/clients/{id}', fn(Request $request, array $params, array $context) => $clientController->update($request, $params, $context), [$authMiddleware, $adminOnly]);
$router->add('DELETE', '/api/clients/{id}', fn(Request $request, array $params, array $context) => $clientController->destroy($params, $context), [$authMiddleware, $adminOnly]);

$router->add('GET', '/api/tasks', fn(Request $request, array $params, array $context) => $taskController->index($context), [$authMiddleware]);
$router->add('POST', '/api/tasks', fn(Request $request, array $params, array $context) => $taskController->store($request, $context), [$authMiddleware]);
$router->add('PATCH', '/api/tasks/{id}/status', fn(Request $request, array $params, array $context) => $taskController->updateStatus($request, $params, $context), [$authMiddleware]);

$router->add('GET', '/api/invoices', fn(Request $request, array $params, array $context) => $invoiceController->index($context), [$authMiddleware]);
$router->add('POST', '/api/invoices', fn(Request $request, array $params, array $context) => $invoiceController->store($request, $context), [$authMiddleware, $adminOnly]);
$router->add('PATCH', '/api/invoices/{id}', fn(Request $request, array $params, array $context) => $invoiceController->update($request, $params, $context), [$authMiddleware, $adminOnly]);

$router->add('GET', '/api/plans', fn(Request $request, array $params, array $context) => $planController->index($context), [$authMiddleware]);
$router->add('GET', '/api/plans/{id}', fn(Request $request, array $params, array $context) => $planController->show($params, $context), [$authMiddleware]);
$router->add('POST', '/api/plans', fn(Request $request, array $params, array $context) => $planController->store($request, $context), [$authMiddleware, $adminOnly]);
$router->add('PATCH', '/api/plans/{id}', fn(Request $request, array $params, array $context) => $planController->update($request, $params, $context), [$authMiddleware, $adminOnly]);
$router->add('DELETE', '/api/plans/{id}', fn(Request $request, array $params, array $context) => $planController->destroy($params, $context), [$authMiddleware, $adminOnly]);

$router->add('GET', '/api/client-reports', fn(Request $request, array $params, array $context) => $clientReportController->index($context), [$authMiddleware]);
$router->add('POST', '/api/client-reports', fn(Request $request, array $params, array $context) => $clientReportController->store($request, $context), [$authMiddleware, $adminOnly]);
$router->add('GET', '/api/client-reports/{id}', fn(Request $request, array $params, array $context) => $clientReportController->show($params, $context), [$authMiddleware, $adminOnly]);
$router->add('PATCH', '/api/client-reports/{id}', fn(Request $request, array $params, array $context) => $clientReportController->update($request, $params, $context), [$authMiddleware, $adminOnly]);
$router->add('DELETE', '/api/client-reports/{id}', fn(Request $request, array $params, array $context) => $clientReportController->destroy($params, $context), [$authMiddleware, $adminOnly]);

$router->add('GET', '/api/marketing-metrics', fn(Request $request, array $params, array $context) => $marketingMetricController->index($request, $context), [$authMiddleware]);
$router->add('POST', '/api/marketing-metrics', fn(Request $request, array $params, array $context) => $marketingMetricController->store($request, $context), [$authMiddleware, $adminOnly]);
$router->add('GET', '/api/marketing-metrics/{id}', fn(Request $request, array $params, array $context) => $marketingMetricController->show($params, $context), [$authMiddleware, $adminOnly]);
$router->add('PATCH', '/api/marketing-metrics/{id}', fn(Request $request, array $params, array $context) => $marketingMetricController->update($request, $params, $context), [$authMiddleware, $adminOnly]);
$router->add('PUT', '/api/marketing-metrics/{id}', fn(Request $request, array $params, array $context) => $marketingMetricController->update($request, $params, $context), [$authMiddleware, $adminOnly]);
$router->add('DELETE', '/api/marketing-metrics/{id}', fn(Request $request, array $params, array $context) => $marketingMetricController->destroy($params, $context), [$authMiddleware, $adminOnly]);

return $router;
