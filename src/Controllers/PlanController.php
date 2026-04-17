<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\PlanRepository;

final class PlanController
{
    public function __construct(private readonly PlanRepository $plans)
    {
    }

    public function index(array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $role = (string) ($context['user']['role'] ?? '');
        $activeOnly = $role === 'cliente';
        $items = $this->plans->allByOrganization($org, $activeOnly);
        Response::json(['data' => $items]);
    }

    public function show(array $params, array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'id inválido'], 422);
            return;
        }

        $row = $this->plans->findByOrganizationAndId($org, $id);
        if ($row === null) {
            Response::json(['error' => 'not_found', 'message' => 'Plano não encontrado'], 404);
            return;
        }

        $role = (string) ($context['user']['role'] ?? '');
        if ($role === 'cliente' && empty($row['is_active'])) {
            Response::json(['error' => 'forbidden', 'message' => 'Plano indisponível'], 403);
            return;
        }

        Response::json(['data' => $row]);
    }

    public function store(Request $request, array $context): void
    {
        $body = $request->body;
        if (!is_array($body)) {
            $body = [];
        }
        if (trim((string) ($body['name'] ?? '')) === '') {
            Response::json(['error' => 'validation_error', 'message' => 'name é obrigatório'], 422);
            return;
        }

        $org = (int) $context['user']['organization_id'];
        try {
            $id = $this->plans->create($org, [
                'name' => (string) $body['name'],
                'slug' => isset($body['slug']) ? (string) $body['slug'] : '',
                'description' => $body['description'] ?? '',
                'price' => isset($body['price']) ? (float) $body['price'] : 0.0,
                'billing_cycle' => (string) ($body['billing_cycle'] ?? 'monthly'),
                'features' => is_array($body['features'] ?? null) ? $body['features'] : [],
                'is_active' => (bool) ($body['is_active'] ?? true),
                'sort_order' => isset($body['sort_order']) ? (int) $body['sort_order'] : 0,
            ]);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getMessage();
            if ($code === 'slug_exists') {
                Response::json(['error' => 'conflict', 'message' => 'Já existe um plano com este slug'], 409);
                return;
            }
            Response::json(['error' => 'validation_error', 'message' => 'Dados do plano inválidos'], 422);
            return;
        }

        $row = $this->plans->findByOrganizationAndId($org, $id);
        Response::json(['message' => 'Plano criado', 'data' => $row], 201);
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

        $allowed = ['name', 'slug', 'description', 'price', 'billing_cycle', 'features', 'is_active', 'sort_order'];
        $patch = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if ($key === 'price') {
                $patch['price'] = (float) $body['price'];
                continue;
            }
            if ($key === 'is_active') {
                $patch['is_active'] = (bool) $body['is_active'];
                continue;
            }
            if ($key === 'sort_order') {
                $patch['sort_order'] = (int) $body['sort_order'];
                continue;
            }
            if ($key === 'features') {
                $patch['features'] = is_array($body['features']) ? $body['features'] : [];
                continue;
            }
            $patch[$key] = $body[$key];
        }

        if ($patch === []) {
            Response::json(['error' => 'validation_error', 'message' => 'Nenhum campo para atualizar'], 422);
            return;
        }

        if (isset($patch['name']) && trim((string) $patch['name']) === '') {
            Response::json(['error' => 'validation_error', 'message' => 'name não pode ser vazio'], 422);
            return;
        }
        if (isset($patch['name'])) {
            $patch['name'] = trim((string) $patch['name']);
        }

        try {
            $ok = $this->plans->updateForOrganization($org, $id, $patch);
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'slug_exists') {
                Response::json(['error' => 'conflict', 'message' => 'Já existe um plano com este slug'], 409);
                return;
            }
            Response::json(['error' => 'validation_error', 'message' => 'Dados do plano inválidos'], 422);
            return;
        }

        if (!$ok) {
            Response::json(['error' => 'not_found', 'message' => 'Plano não encontrado'], 404);
            return;
        }

        $row = $this->plans->findByOrganizationAndId($org, $id);
        Response::json(['message' => 'Plano atualizado', 'data' => $row]);
    }

    public function destroy(array $params, array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'id inválido'], 422);
            return;
        }

        $ok = $this->plans->deleteForOrganization($org, $id);
        if (!$ok) {
            Response::json(['error' => 'not_found', 'message' => 'Plano não encontrado'], 404);
            return;
        }

        Response::json(['message' => 'Plano removido'], 200);
    }
}
