<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientReportRepository;
use App\Repositories\ClientRepository;
use MongoDB\BSON\Binary;

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
        $role = (string) ($context['user']['role'] ?? '');

        if ($role === 'cliente') {
            $client = $this->clients->resolvePortalClienteRow($org, $context['user']);

            if ($client === null) {
                Response::json(['data' => []]);
                return;
            }

            $items = $this->reports->allByOrganizationAndClient($org, (int) $client['id']);
            Response::json(['data' => $items]);
            return;
        }

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
        $url = trim((string) ($payload['url'] ?? ''));

        if ($clientId <= 0 || $title === '') {
            Response::json(['error' => 'validation_error', 'message' => 'client_id e title são obrigatórios'], 422);
            return;
        }

        $attachmentBins = $this->parseAttachmentsFromPayload($payload);
        if ($url === '' && $attachmentBins === []) {
            Response::json([
                'error' => 'validation_error',
                'message' => 'Informe uma URL ou pelo menos um anexo (arquivo em base64).',
            ], 422);
            return;
        }

        $org = (int) $context['user']['organization_id'];
        if ($this->clients->findByOrganizationAndId($org, $clientId) === null) {
            Response::json(['error' => 'validation_error', 'message' => 'Cliente não encontrado nesta organização'], 422);
            return;
        }

        $createPayload = [
            'client_id' => $clientId,
            'title' => $title,
            'url' => $url !== '' ? $url : null,
            'summary' => $payload['summary'] ?? null,
            'status' => (string) ($payload['status'] ?? 'published'),
        ];
        if ($attachmentBins !== []) {
            $createPayload['attachments'] = $attachmentBins;
        }

        $id = $this->reports->create($org, $createPayload);

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

        $role = (string) ($context['user']['role'] ?? '');
        if ($role === 'cliente') {
            $client = $this->clients->resolvePortalClienteRow($org, $context['user']);
            if ($client === null || (int) $row['client_id'] !== (int) $client['id']) {
                Response::json(['error' => 'forbidden', 'message' => 'Acesso negado'], 403);
                return;
            }
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

        $allowed = ['title', 'url', 'summary', 'status', 'client_id', 'attachments'];
        $patch = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if ($key === 'client_id') {
                $patch['client_id'] = (int) $body['client_id'];
                continue;
            }
            if ($key === 'attachments') {
                $patch['attachments'] = $this->parseAttachmentsFromPayload(['attachments' => $body['attachments']]);
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

        if (array_key_exists('title', $patch) && trim((string) $patch['title']) === '') {
            Response::json(['error' => 'validation_error', 'message' => 'title não pode ser vazio'], 422);
            return;
        }

        $current = $this->reports->findByOrganizationAndId($org, $id);
        if ($current === null) {
            Response::json(['error' => 'not_found', 'message' => 'Relatório não encontrado'], 404);
            return;
        }

        $mergedUrl = array_key_exists('url', $patch) ? trim((string) ($patch['url'] ?? '')) : trim((string) ($current['url'] ?? ''));
        $mergedAttachments = array_key_exists('attachments', $patch) ? $patch['attachments'] : null;
        if ($mergedAttachments !== null && $mergedUrl === '' && $mergedAttachments === []) {
            Response::json([
                'error' => 'validation_error',
                'message' => 'Defina uma URL ou mantenha/envie pelo menos um anexo.',
            ], 422);
            return;
        }
        if ($mergedAttachments === null) {
            $attCount = is_array($current['attachments'] ?? null) ? count($current['attachments']) : 0;
            if ($mergedUrl === '' && $attCount === 0) {
                Response::json([
                    'error' => 'validation_error',
                    'message' => 'Relatório precisa de URL ou anexos.',
                ], 422);
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

    /**
     * @return list<array{filename:string, mime_type:string, data:Binary}>
     */
    private function parseAttachmentsFromPayload(array $payload): array
    {
        $raw = $payload['attachments'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        if ($raw === []) {
            return [];
        }
        if (count($raw) > ClientReportRepository::MAX_ATTACHMENTS) {
            Response::json([
                'error' => 'validation_error',
                'message' => 'Máximo de ' . ClientReportRepository::MAX_ATTACHMENTS . ' anexos por relatório.',
            ], 422);
        }

        $out = [];
        $total = 0;

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $filename = trim((string) ($item['filename'] ?? ''));
            $mime = trim((string) ($item['mime_type'] ?? 'application/octet-stream'));
            $b64 = $item['data_base64'] ?? '';
            if (!is_string($b64) || $filename === '') {
                Response::json(['error' => 'validation_error', 'message' => 'Cada anexo precisa de filename e data_base64.'], 422);
            }

            if (preg_match('/^data:([^;]+);base64,(.+)$/s', $b64, $m)) {
                if ($mime === '' || $mime === 'application/octet-stream') {
                    $mime = trim($m[1]);
                }
                $b64 = $m[2];
            }

            $decoded = base64_decode($b64, true);
            if ($decoded === false || $decoded === '') {
                Response::json(['error' => 'validation_error', 'message' => 'Base64 inválido no arquivo: ' . $filename], 422);
            }

            $len = strlen($decoded);
            if ($len > ClientReportRepository::MAX_ATTACHMENT_BYTES) {
                Response::json([
                    'error' => 'validation_error',
                    'message' => 'Arquivo muito grande (máx. ' . (ClientReportRepository::MAX_ATTACHMENT_BYTES / 1024 / 1024) . ' MB): ' . $filename,
                ], 422);
            }

            $total += $len;
            if ($total > ClientReportRepository::MAX_TOTAL_ATTACHMENT_BYTES) {
                Response::json([
                    'error' => 'validation_error',
                    'message' => 'Soma dos anexos excede o limite permitido.',
                ], 422);
            }

            $out[] = [
                'filename' => $filename,
                'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
                'data' => new Binary($decoded, Binary::TYPE_GENERIC),
            ];
        }

        return $out;
    }
}
