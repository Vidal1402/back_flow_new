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
}
