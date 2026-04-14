<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class HealthController
{
    public function __invoke(Request $request): void
    {
        Response::json([
            'status' => 'ok',
            'service' => 'php-mvp-api',
            'timestamp' => date(DATE_ATOM),
        ]);
    }
}
