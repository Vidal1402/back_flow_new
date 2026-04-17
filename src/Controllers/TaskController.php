<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Repositories\TaskRepository;

final class TaskController
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ClientRepository $clients,
    ) {
    }

    public function index(array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $role = (string) ($context['user']['role'] ?? '');
        if ($role === 'cliente') {
            $uid = (int) ($context['user']['id'] ?? 0);
            $linked = $this->clients->resolvePortalClienteRow($org, $context['user']);
            $linkedId = $linked !== null ? (int) ($linked['id'] ?? 0) : null;
            $items = $this->tasks->allForPortalCliente($org, $uid, $linkedId !== null && $linkedId > 0 ? $linkedId : null);
            Response::json(['data' => $items]);
            return;
        }

        $items = $this->tasks->allByOrganization($org);
        Response::json(['data' => $items]);
    }

    public function store(Request $request, array $context): void
    {
        $payload = $request->body;
        if (!is_array($payload)) {
            $payload = [];
        }
        if (empty($payload['title'])) {
            Response::json(['error' => 'validation_error', 'message' => 'title é obrigatório'], 422);
        }

        $org = (int) $context['user']['organization_id'];
        $ownerId = (int) $context['user']['id'];
        $role = (string) ($context['user']['role'] ?? '');

        if ($role === 'cliente') {
            $mine = $this->clients->resolvePortalClienteRow($org, $context['user']);
            if ($mine === null) {
                Response::json([
                    'error' => 'forbidden',
                    'message' => 'Cadastro de cliente não vinculado a este login. Peça ao admin para liberar o acesso.',
                ], 403);
                return;
            }
            $payload['client_id'] = (int) ($mine['id'] ?? 0);
            $payload['owner_id'] = $ownerId;
        }

        $id = $this->tasks->create($payload, $org, $ownerId);

        Response::json([
            'message' => 'Tarefa criada',
            'id' => $id,
        ], 201);
    }

    public function updateStatus(Request $request, array $params, array $context): void
    {
        $status = trim((string) ($request->body['status'] ?? ''));
        if ($status === '') {
            Response::json(['error' => 'validation_error', 'message' => 'status é obrigatório'], 422);
        }

        $taskId = (int) ($params['id'] ?? 0);
        if ($taskId <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'id inválido'], 422);
        }

        $org = (int) $context['user']['organization_id'];
        $role = (string) ($context['user']['role'] ?? '');
        if ($role === 'cliente') {
            $raw = $this->tasks->rawByOrganizationAndId($org, $taskId);
            if ($raw === null) {
                Response::json(['error' => 'not_found', 'message' => 'Tarefa não encontrada'], 404);
                return;
            }
            $uid = (int) ($context['user']['id'] ?? 0);
            $linked = $this->clients->resolvePortalClienteRow($org, $context['user']);
            $linkedId = $linked !== null && (int) ($linked['id'] ?? 0) > 0 ? (int) $linked['id'] : null;
            if (!TaskRepository::portalClienteCanAccessTask($raw, $uid, $linkedId)) {
                Response::json(['error' => 'forbidden', 'message' => 'Acesso negado a esta solicitação'], 403);
                return;
            }
        }

        $updated = $this->tasks->updateStatus($taskId, $status, $org);
        if (!$updated) {
            Response::json(['error' => 'not_found', 'message' => 'Tarefa não encontrada'], 404);
        }

        Response::json(['message' => 'Status atualizado']);
    }
}
