<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Repositories\InvoiceRepository;

final class InvoiceController
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ClientRepository $clients,
    ) {
    }

    public function index(array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $role = (string) ($context['user']['role'] ?? '');
        if ($role === 'cliente') {
            $mine = $this->clients->resolvePortalClienteRow($org, $context['user']);
            $cid = $mine !== null ? (int) ($mine['id'] ?? 0) : 0;
            $items = $cid > 0 ? $this->invoices->allByOrganization($org, $cid) : [];
            Response::json(['data' => $items]);
            return;
        }

        $items = $this->invoices->allByOrganization($org);
        Response::json(['data' => $items]);
    }

    public function store(Request $request, array $context): void
    {
        $payload = $request->body;
        if (!is_array($payload)) {
            $payload = [];
        }
        if (empty($payload['period']) || empty($payload['due_date']) || empty($payload['amount'])) {
            Response::json([
                'error' => 'validation_error',
                'message' => 'period, amount e due_date são obrigatórios',
            ], 422);
        }

        $clientId = (int) ($payload['client_id'] ?? 0);
        if ($clientId <= 0) {
            Response::json([
                'error' => 'validation_error',
                'message' => 'client_id é obrigatório para vincular a fatura ao portal do cliente',
            ], 422);
            return;
        }

        $org = (int) $context['user']['organization_id'];
        if ($this->clients->findByOrganizationAndId($org, $clientId) === null) {
            Response::json(['error' => 'validation_error', 'message' => 'Cliente não encontrado nesta organização'], 422);
            return;
        }

        $invoiceCode = trim((string) ($payload['invoice_code'] ?? ''));
        if ($invoiceCode === '') {
            $invoiceCode = 'INV-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        $id = $this->invoices->create([
            'invoice_code' => $invoiceCode,
            'period' => (string) $payload['period'],
            'amount' => (float) $payload['amount'],
            'due_date' => (string) $payload['due_date'],
            'status' => (string) ($payload['status'] ?? 'Pendente'),
            'method' => (string) ($payload['method'] ?? 'Pix'),
            'client_id' => $clientId,
        ], $org);

        Response::json([
            'message' => 'Fatura criada',
            'id' => $id,
            'invoice_code' => $invoiceCode,
        ], 201);
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

        $patch = [];
        if (array_key_exists('status', $body)) {
            $patch['status'] = (string) $body['status'];
        }
        if (array_key_exists('client_id', $body)) {
            $v = $body['client_id'];
            $patch['client_id'] = $v === null || $v === '' ? null : (int) $v;
            if ($patch['client_id'] !== null && $patch['client_id'] > 0) {
                if ($this->clients->findByOrganizationAndId($org, $patch['client_id']) === null) {
                    Response::json(['error' => 'validation_error', 'message' => 'Cliente não encontrado nesta organização'], 422);
                    return;
                }
            }
        }

        if ($patch === []) {
            Response::json(['error' => 'validation_error', 'message' => 'Nenhum campo para atualizar'], 422);
            return;
        }

        $ok = $this->invoices->updateForOrganization($org, $id, $patch);
        if (!$ok) {
            Response::json(['error' => 'not_found', 'message' => 'Fatura não encontrada'], 404);
            return;
        }

        $row = $this->invoices->findByOrganizationAndId($org, $id);
        Response::json(['message' => 'Fatura atualizada', 'data' => $row]);
    }
}
