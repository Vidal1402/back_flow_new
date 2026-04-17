<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\JWT;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Repositories\UserRepository;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ?ClientRepository $clients = null,
    ) {
    }

    public function register(Request $request): void
    {
        Response::json([
            'error' => 'forbidden',
            'message' => 'Registro público desativado. Use POST /api/admin/users com JWT de admin.',
        ], 403);
    }

    public function adminCreateUser(Request $request, array $context): void
    {
        $organizationId = (int) ($context['user']['organization_id'] ?? 1);
        $email = trim((string) ($request->body['email'] ?? ''));
        $existing = $email !== '' ? $this->users->findByEmail($email) : null;
        $user = $this->createUserFromPayload($request, $organizationId);
        $this->autoBindClientUser($user);
        $wasExisting = $existing !== null && (int) ($existing['organization_id'] ?? 0) === $organizationId;

        Response::json([
            'message' => $wasExisting ? 'Acesso atualizado por admin' : 'Usuário criado por admin',
            'user' => $user,
        ], $wasExisting ? 200 : 201);
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

        $this->autoBindClientUser($user);

        $appKey = trim((string) Env::get('APP_KEY', ''));
        if ($appKey === '') {
            Response::json([
                'error' => 'server_error',
                'message' => 'APP_KEY não configurada no servidor (Railway / .env).',
            ], 500);
        }

        $ttl = (int) (Env::get('JWT_TTL', '3600') ?? '3600');
        $payload = [
            'sub' => (int) $user['id'],
            'role' => $user['role'],
            'org' => (int) $user['organization_id'],
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'iat' => time(),
            'exp' => time() + $ttl,
        ];

        $token = JWT::encode($payload, $appKey);

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

    private function createUserFromPayload(Request $request, int $organizationId): array
    {
        $name = trim((string) ($request->body['name'] ?? ''));
        $email = trim((string) ($request->body['email'] ?? ''));
        $password = (string) ($request->body['password'] ?? '');
        $role = (string) ($request->body['role'] ?? 'colaborador');
        $resetIfExists = (bool) ($request->body['reset_if_exists'] ?? false);

        if ($name === '' || $email === '' || $password === '') {
            Response::json(['error' => 'validation_error', 'message' => 'name, email e password são obrigatórios'], 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['error' => 'validation_error', 'message' => 'email inválido'], 422);
        }

        if (!in_array($role, ['admin', 'colaborador', 'cliente'], true)) {
            Response::json(['error' => 'validation_error', 'message' => 'role deve ser admin, colaborador ou cliente'], 422);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $existing = $this->users->findByEmail($email);
        if ($existing) {
            if ((int) ($existing['organization_id'] ?? 0) !== $organizationId) {
                Response::json(['error' => 'conflict', 'message' => 'Email já está em uso em outra organização'], 409);
            }

            if (!$resetIfExists) {
                Response::json(['error' => 'conflict', 'message' => 'Email já cadastrado'], 409);
            }

            $updated = $this->users->updateAccessById((int) $existing['id'], $organizationId, $name, $hash, $role);
            if (!$updated) {
                Response::json(['error' => 'server_error', 'message' => 'Falha ao atualizar usuário existente'], 500);
            }

            $user = $this->users->findById((int) $existing['id']);
            if (!$user) {
                Response::json(['error' => 'server_error', 'message' => 'Falha ao carregar usuário atualizado'], 500);
            }

            return $user;
        }

        $id = $this->users->create($name, $email, $hash, $role, $organizationId);
        $user = $this->users->findById($id);

        if (!$user) {
            Response::json(['error' => 'server_error', 'message' => 'Falha ao carregar usuário recém-criado'], 500);
        }

        return $user;
    }

    /**
     * Vincula o usuário com perfil cliente ao cadastro em clients pelo mesmo e-mail na organização.
     *
     * @param array<string, mixed> $user
     */
    private function autoBindClientUser(array $user): void
    {
        if ($this->clients === null) {
            return;
        }
        if ((string) ($user['role'] ?? '') !== 'cliente') {
            return;
        }

        $organizationId = (int) ($user['organization_id'] ?? 0);
        $userId = (int) ($user['id'] ?? 0);
        $email = (string) ($user['email'] ?? '');
        if ($organizationId <= 0 || $userId <= 0 || trim($email) === '') {
            return;
        }

        $this->clients->bindUserByOrganizationAndEmail($organizationId, $email, $userId);
    }
}
