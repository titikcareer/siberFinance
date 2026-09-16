<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

final class JsonResponse
{
    public static function ok(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    public static function error(Response $response, string $message, int $status = 400, mixed $details = null): Response
    {
        $body = ['success' => false, 'error' => $message];
        if ($details !== null) {
            $body['details'] = $details;
        }
        $response->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
