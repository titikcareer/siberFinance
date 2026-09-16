<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\JwtService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface as Response;

final class JwtAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JwtService $jwt,
        private ResponseFactoryInterface $responseFactory
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized('Missing or malformed Authorization header');
        }

        $token = substr($authHeader, 7);
        $claims = $this->jwt->verify($token);

        if ($claims === null) {
            return $this->unauthorized('Invalid or expired token');
        }

        // Simpan claims ke request attribute agar bisa diakses controller (misal: user_id)
        $request = $request->withAttribute('user_id', $claims['sub'] ?? null);
        $request = $request->withAttribute('claims', $claims);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = $this->responseFactory->createResponse(401);
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
