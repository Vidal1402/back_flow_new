<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientReportRepository;
use App\Repositories\ClientRepository;

final class ClientReportController
{
    public function __construct(
        private readonly ClientReportRepository $reports,
        private readonly ClientRepository $clients
    ) {
    }

    public function index(array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $items = $this->reports->allByOrganization($org);
        Response::json(['data' => $items]);
    }

    public function store(Request $request, array $context): void
    {
        $payload = $request->body;
        if (!is_array($payload)) {
            $payload = [];
        }

        $clientId = (int) ($payload['client_id'] ?? 0);
        $title = trim((string) ($payload['title'] ?? ''));
        if ($clientId <= 0 || $title === '') {
            Response::json(['error' => 'validation_error', 'message' => 'client_id e title são obrigatórios'], 422);
            return;
        }

        $org = (int) $context['user']['organization_id'];
        if ($this->clients->findByOrganizationAndId($org, $clientId) === null) {
            Response::json(['error' => 'validation_error', 'message' => 'Cliente não encontrado nesta organização'], 422);
            return;
        }

        $id = $this->reports->create($org, [
            'client_id' => $clientId,
            'title' => $title,
            'url' => $payload['url'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'status' => (string) ($payload['status'] ?? 'published'),
        ]);

        $row = $this->reports->findByOrganizationAndId($org, $id);
        Response::json(['message' => 'Relatório criado', 'id' => $id, 'data' => $row], 201);
    }

    public function show(array $params, array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'id inválido'], 422);
            return;
        }

        $row = $this->reports->findByOrganizationAndId($org, $id);
        if ($row === null) {
            Response::json(['error' => 'not_found', 'message' => 'Relatório não encontrado'], 404);
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

        $allowed = ['title', 'url', 'summary', 'status', 'client_id'];
        $patch = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if ($key === 'client_id') {
                $patch['client_id'] = (int) $body['client_id'];
                continue;
            }
            $patch[$key] = $body[$key];
        }

        if (array_key_exists('client_id', $patch)) {
            if ($patch['client_id'] <= 0) {
                Response::json(['error' => 'validation_error', 'message' => 'client_id inválido'], 422);
                return;
            }
            if ($this->clients->findByOrganizationAndId($org, $patch['client_id']) === null) {
                Response::json(['error' => 'validation_error', 'message' => 'Cliente não encontrado nesta organização'], 422);
                return;
            }
        }

        if ($patch === []) {
            Response::json(['error' => 'validation_error', 'message' => 'Nenhum campo para atualizar'], 422);
            return;
        }

        $ok = $this->reports->updateForOrganization($org, $id, $patch);
        if (!$ok) {
            Response::json(['error' => 'not_found', 'message' => 'Relatório não encontrado'], 404);
            return;
        }

        $row = $this->reports->findByOrganizationAndId($org, $id);
        Response::json(['message' => 'Relatório atualizado', 'data' => $row]);
    }

    public function destroy(array $params, array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'id inválido'], 422);
            return;
        }

        $ok = $this->reports->deleteForOrganization($org, $id);
        if (!$ok) {
            Response::json(['error' => 'not_found', 'message' => 'Relatório não encontrado'], 404);
            return;
        }

        Response::json(['message' => 'Relatório removido'], 200);
    }
}
