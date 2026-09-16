<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuditController
{
    public function index(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $params = $request->getQueryParams();
        $limit = min((int) ($params['limit'] ?? 100), 500);

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, action, entity_type, entity_id, old_value, new_value, ip_address, created_at
             FROM audit_logs WHERE user_id = :uid ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(function ($r) {
            $r['old_value'] = $r['old_value'] ? json_decode($r['old_value'], true) : null;
            $r['new_value'] = $r['new_value'] ? json_decode($r['new_value'], true) : null;
            return $r;
        }, $stmt->fetchAll());

        return JsonResponse::ok($response, $rows);
    }
}
