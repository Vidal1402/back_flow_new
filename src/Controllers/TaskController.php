<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\TaskRepository;

final class TaskController
{
    public function __construct(private readonly TaskRepository $tasks)
    {
    }

    public function index(array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $items = $this->tasks->allByOrganization($org);
        Response::json(['data' => $items]);
    }

    public function store(Request $request, array $context): void
    {
        $payload = $request->body;
        if (empty($payload['title'])) {
            Response::json(['error' => 'validation_error', 'message' => 'title é obrigatório'], 422);
        }

        $org = (int) $context['user']['organization_id'];
        $ownerId = (int) $context['user']['id'];
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
        $updated = $this->tasks->updateStatus($taskId, $status, $org);
        if (!$updated) {
            Response::json(['error' => 'not_found', 'message' => 'Tarefa não encontrada'], 404);
        }

        Response::json(['message' => 'Status atualizado']);
    }
}
