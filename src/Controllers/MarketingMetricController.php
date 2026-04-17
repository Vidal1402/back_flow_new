<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Repositories\MarketingMetricRepository;

final class MarketingMetricController
{
    public function __construct(
        private readonly MarketingMetricRepository $metrics,
        private readonly ClientRepository $clients
    ) {
    }

    public function index(Request $request, array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $role = (string) ($context['user']['role'] ?? '');

        if ($role === 'cliente') {
            $client = $this->clients->resolvePortalClienteRow($org, $context['user']);

            if ($client === null) {
                Response::json(['data' => []]);
                return;
            }

            $period = trim((string) ($request->query['period'] ?? ''));
            $items = $this->metrics->allByOrganization($org, (int) $client['id'], $period !== '' ? $period : null);
            Response::json(['data' => $items]);
            return;
        }

        $clientId = (int) ($request->query['client_id'] ?? 0);
        $period = trim((string) ($request->query['period'] ?? ''));
        $items = $this->metrics->allByOrganization($org, $clientId > 0 ? $clientId : null, $period !== '' ? $period : null);
        Response::json(['data' => $items]);
    }

    public function store(Request $request, array $context): void
    {
        $body = is_array($request->body) ? $request->body : [];
        $clientId = (int) ($body['client_id'] ?? 0);
        if ($clientId <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'ID do cliente inválido para métricas'], 422);
            return;
        }

        $org = (int) $context['user']['organization_id'];
        if ($this->clients->findByOrganizationAndId($org, $clientId) === null) {
            Response::json(['error' => 'validation_error', 'message' => 'Cliente não encontrado nesta organização'], 422);
            return;
        }

        $payload = $this->sanitizePayload($body);
        try {
            $saved = $this->metrics->upsertForClientAndPeriod($org, $clientId, $payload);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'E11000') || str_contains($msg, 'duplicate key')) {
                Response::json([
                    'error' => 'conflict',
                    'message' => 'Conflito no banco (índice único legado em marketing_metrics). Faça deploy da última versão do backend para aplicar a migração de índices e tente de novo.',
                ], 409);
                return;
            }
            Response::json([
                'error' => 'server_error',
                'message' => 'Falha ao salvar métricas no banco de dados.',
            ], 500);
            return;
        }

        Response::json([
            'message' => 'Métricas salvas',
            'data' => $saved,
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

        $row = $this->metrics->findByOrganizationAndId($org, $id);
        if ($row === null) {
            Response::json(['error' => 'not_found', 'message' => 'Métricas não encontradas'], 404);
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

        $body = is_array($request->body) ? $request->body : [];
        $clientId = (int) ($body['client_id'] ?? 0);
        if ($clientId <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'ID do cliente inválido para métricas'], 422);
            return;
        }

        if ($this->clients->findByOrganizationAndId($org, $clientId) === null) {
            Response::json(['error' => 'validation_error', 'message' => 'Cliente não encontrado nesta organização'], 422);
            return;
        }

        $payload = $this->sanitizePayload($body);
        $ok = $this->metrics->updateForOrganization($org, $id, $clientId, $payload);
        if (!$ok) {
            Response::json(['error' => 'not_found', 'message' => 'Métricas não encontradas'], 404);
            return;
        }

        $row = $this->metrics->findByOrganizationAndId($org, $id);
        Response::json(['message' => 'Métricas atualizadas', 'data' => $row]);
    }

    public function destroy(array $params, array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'validation_error', 'message' => 'id inválido'], 422);
            return;
        }

        $ok = $this->metrics->deleteForOrganization($org, $id);
        if (!$ok) {
            Response::json(['error' => 'not_found', 'message' => 'Métricas não encontradas'], 404);
            return;
        }

        Response::json(['message' => 'Métricas removidas']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $body): array
    {
        unset(
            $body['id'],
            $body['_id'],
            $body['organization_id'],
            $body['created_at'],
            $body['updated_at']
        );

        return $body;
    }
}
