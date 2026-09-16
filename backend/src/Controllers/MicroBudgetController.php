<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DynamicAllocationEngine;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MicroBudgetController
{
    public function __construct(private DynamicAllocationEngine $engine)
    {
    }

    public function recalculate(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');

        try {
            $allocations = $this->engine->recalculateForBudget($args['budgetId'], $userId);
        } catch (\RuntimeException $e) {
            return JsonResponse::error($response, $e->getMessage(), 404);
        }

        return JsonResponse::ok($response, $allocations);
    }
}
