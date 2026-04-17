<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;

final class ClientController
{
    public function __construct(private readonly ClientRepository $clients)
    {
    }

    public function index(array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $items = $this->clients->allByOrganization($org);
        Response::json(['data' => $items]);
    }

    public function meForPortal(array $context): void
    {
        $role = (string) ($context['user']['role'] ?? '');
        if ($role !== 'cliente') {
            Response::json(['error' => 'forbidden', 'message' => 'Disponível para usuários com perfil cliente'], 403);
            return;
        }

        $org = (int) $context['user']['organization_id'];
        $uid = (int) ($context['user']['id'] ?? 0);
        $email = (string) ($context['user']['email'] ?? '');

        $client = $this->clients->findByUserId($uid)
            ?? $this->clients->findByOrganizationAndEmail($org, $email);

        Response::json(['data' => $client]);
    }

    public function store(Request $request, array $context): void
    {
        $payload = $request->body;
        if (empty($payload['name']) || empty($payload['empresa']) || empty($payload['email'])) {
            Response::json(['error' => 'validation_error', 'message' => 'name, empresa e email são obrigatórios'], 422);
        }

        $org = (int) $context['user']['organization_id'];
        $id = $this->clients->create($payload, $org);

        Response::json([
            'message' => 'Cliente criado',
            'id' => $id,
        ], 201);
    }

    public function show(array $params, array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'id inválido'], 422);
            return;
        }

        $row = $this->clients->findByOrganizationAndId($org, $id);
        if ($row === null) {
            Response::json(['error' => 'not_found', 'message' => 'Cliente não encontrado'], 404);
            return;
        }

        Response::json(['data' => $row]);
    }

    public function update(Request $request, array $params, array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'id inválido'], 422);
            return;
        }

        $body = $request->body;
        if (!is_array($body)) {
            $body = [];
        }

        $allowed = ['name', 'empresa', 'email', 'telefone', 'plano', 'valor', 'status'];
        $patch = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if ($key === 'valor') {
                $patch['valor'] = is_numeric($body['valor']) ? (float) $body['valor'] : 0;
                continue;
            }
            if ($key === 'telefone' && $body['telefone'] === '') {
                $patch['telefone'] = null;
                continue;
            }
            $patch[$key] = $body[$key];
        }

        if ($patch === []) {
            Response::json(['error' => 'validation_error', 'message' => 'Nenhum campo para atualizar'], 422);
            return;
        }

        $ok = $this->clients->updateForOrganization($org, $id, $patch);
        if (!$ok) {
            Response::json(['error' => 'not_found', 'message' => 'Cliente não encontrado'], 404);
            return;
        }

        $row = $this->clients->findByOrganizationAndId($org, $id);
        Response::json(['message' => 'Cliente atualizado', 'data' => $row]);
    }

    public function destroy(array $params, array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'id inválido'], 422);
            return;
        }

        $ok = $this->clients->deleteForOrganization($org, $id);
        if (!$ok) {
            Response::json(['error' => 'not_found', 'message' => 'Cliente não encontrado'], 404);
            return;
        }

        Response::json(['message' => 'Cliente removido'], 200);
    }
}
