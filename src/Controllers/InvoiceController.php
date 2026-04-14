<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\InvoiceRepository;

final class InvoiceController
{
    public function __construct(private readonly InvoiceRepository $invoices)
    {
    }

    public function index(array $context): void
    {
        $org = (int) $context['user']['organization_id'];
        $items = $this->invoices->allByOrganization($org);
        Response::json(['data' => $items]);
    }
}
