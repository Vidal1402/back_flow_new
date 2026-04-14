<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\JWT;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;

final class AuthController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function register(Request $request): void
    {
        $name = trim((string) ($request->body['name'] ?? ''));
        $email = trim((string) ($request->body['email'] ?? ''));
        $password = (string) ($request->body['password'] ?? '');
        $role = (string) ($request->body['role'] ?? 'colaborador');

        if ($name === '' || $email === '' || $password === '') {
            Response::json(['error' => 'validation_error', 'message' => 'name, email e password são obrigatórios'], 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['error' => 'validation_error', 'message' => 'email inválido'], 422);
        }

        if ($this->users->findByEmail($email)) {
            Response::json(['error' => 'conflict', 'message' => 'Email já cadastrado'], 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $id = $this->users->create($name, $email, $hash, $role);
        $user = $this->users->findById($id);

        Response::json([
            'message' => 'Usuário criado',
            'user' => $user,
        ], 201);
    }

    public function login(Request $request): void
    {
        $email = trim((string) ($request->body['email'] ?? ''));
        $password = (string) ($request->body['password'] ?? '');

        if ($email === '' || $password === '') {
            Response::json(['error' => 'validation_error', 'message' => 'email e password são obrigatórios'], 422);
        }

        $user = $this->users->findByEmail($email);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            Response::json(['error' => 'unauthorized', 'message' => 'Credenciais inválidas'], 401);
        }

        $ttl = (int) (Env::get('JWT_TTL', '3600') ?? '3600');
        $payload = [
            'sub' => (int) $user['id'],
            'role' => $user['role'],
            'org' => (int) $user['organization_id'],
            'iat' => time(),
            'exp' => time() + $ttl,
        ];

        $token = JWT::encode($payload, (string) Env::get('APP_KEY', ''));

        Response::json([
            'token' => $token,
            'expires_in' => $ttl,
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'organization_id' => (int) $user['organization_id'],
            ],
        ]);
    }

    public function me(array $context): void
    {
        Response::json(['user' => $context['user']]);
    }
}
