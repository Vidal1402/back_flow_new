<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
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
        $limit = (int) ($request->query['limit'] ?? 100);

        if ($role === 'cliente') {
            $uid = (int) ($context['user']['id'] ?? 0);
            $email = (string) ($context['user']['email'] ?? '');
            $client = $this->clients->findByUserId($uid)
                ?? $this->clients->findByOrganizationAndEmail($org, $email);

            if ($client === null) {
                Response::json(['data' => []]);
                return;
            }

            $items = $this->metrics->allByOrganization($org, (int) $client['id'], $limit);
            Response::json(['data' => $items]);
            return;
        }

        $clientId = (int) ($request->query['client_id'] ?? 0);
        $items = $this->metrics->allByOrganization($org, $clientId > 0 ? $clientId : null, $limit);
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

        $this->debugStorePayload($org, $clientId, $body);
        $payload = $this->sanitizePayload($body);
        if (!$this->hasRelevantMetrics($payload)) {
            Response::json([
                'error' => 'validation_error',
                'message' => 'Nenhuma métrica válida foi enviada. Preencha ao menos um valor > 0.',
            ], 422);
            return;
        }

        $saved = $this->metrics->upsertLatestForClient($org, $clientId, $payload);

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
            $body['client_id'],
            $body['organization_id'],
            $body['created_at'],
            $body['updated_at']
        );

        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasRelevantMetrics(array $payload): bool
    {
        foreach ($payload as $value) {
            if ($this->containsPositiveMetric($value)) {
                return true;
            }
        }

        return false;
    }

    private function containsPositiveMetric(mixed $value): bool
    {
        if (is_numeric($value)) {
            return (float) $value > 0;
        }

        if (is_array($value)) {
            foreach ($value as $nested) {
                if ($this->containsPositiveMetric($nested)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function debugStorePayload(int $organizationId, int $clientId, array $body): void
    {
        $enabled = filter_var((string) (Env::get('MARKETING_METRICS_DEBUG_LOG', 'false') ?? 'false'), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return;
        }

        $summary = [
            'event' => 'marketing_metrics.store.received',
            'organization_id' => $organizationId,
            'client_id' => $clientId,
            'keys' => array_values(array_filter(array_map(static fn ($k): ?string => is_string($k) ? $k : null, array_keys($body)))),
            'body' => $body,
        ];
        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            error_log($encoded);
        }
    }
}
