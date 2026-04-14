<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Env;
use App\Core\JWT;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use Exception;

final class AuthMiddleware
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function __invoke(Request $request): array
    {
        $authorization = $request->header('Authorization');
        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            Response::json(['error' => 'unauthorized', 'message' => 'Token ausente'], 401);
        }

        $token = trim(substr($authorization, 7));
        $secret = Env::get('APP_KEY', '');
        if ($secret === '') {
            Response::json(['error' => 'server_error', 'message' => 'APP_KEY não configurada'], 500);
        }

        try {
            $payload = JWT::decode($token, $secret);
        } catch (Exception $e) {
            Response::json(['error' => 'unauthorized', 'message' => $e->getMessage()], 401);
        }

        $userId = (int) ($payload['sub'] ?? 0);
        $user = $this->users->findById($userId);
        if (!$user) {
            Response::json(['error' => 'unauthorized', 'message' => 'Usuário inválido'], 401);
        }

        return ['user' => $user];
    }
}
