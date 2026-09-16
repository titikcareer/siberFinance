<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\AuditLogger;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CategoryController
{
    private const VALID_TYPES = ['income', 'expense', 'transfer'];

    public function index(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM categories WHERE user_id = :uid AND is_archived = 0 ORDER BY type, name ASC'
        );
        $stmt->execute(['uid' => $userId]);

        return JsonResponse::ok($response, $stmt->fetchAll());
    }

    public function store(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = (array) $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? ''));
        $type = (string) ($body['type'] ?? '');

        if ($name === '' || !in_array($type, self::VALID_TYPES, true)) {
            return JsonResponse::error($response, 'Nama kategori dan tipe (income|expense|transfer) wajib valid.', 422);
        }

        $id = AuditLogger::uuid();
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO categories (id, user_id, name, type, parent_id, icon, color)
             VALUES (:id, :uid, :name, :type, :parent_id, :icon, :color)'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'name' => $name,
            'type' => $type,
            'parent_id' => $body['parent_id'] ?? null,
            'icon' => $body['icon'] ?? null,
            'color' => $body['color'] ?? null,
        ]);

        AuditLogger::log($userId, 'create', 'category', $id, null, $body);

        return JsonResponse::ok($response, ['id' => $id], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = $args['id'];
        $body = (array) $request->getParsedBody();

        $pdo = Database::connection();
        $existing = $this->findOwned($pdo, $id, $userId);
        if (!$existing) {
            return JsonResponse::error($response, 'Kategori tidak ditemukan.', 404);
        }

        $name = trim((string) ($body['name'] ?? $existing['name']));
        $icon = $body['icon'] ?? $existing['icon'];
        $color = $body['color'] ?? $existing['color'];

        $stmt = $pdo->prepare('UPDATE categories SET name = :name, icon = :icon, color = :color WHERE id = :id');
        $stmt->execute(['name' => $name, 'icon' => $icon, 'color' => $color, 'id' => $id]);

        AuditLogger::log($userId, 'update', 'category', $id, $existing, $body);

        return JsonResponse::ok($response, ['id' => $id]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = $args['id'];
        $pdo = Database::connection();

        $existing = $this->findOwned($pdo, $id, $userId);
        if (!$existing) {
            return JsonResponse::error($response, 'Kategori tidak ditemukan.', 404);
        }

        $stmt = $pdo->prepare('UPDATE categories SET is_archived = 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);

        AuditLogger::log($userId, 'delete', 'category', $id, $existing, null);

        return JsonResponse::ok($response, ['archived' => true]);
    }

    private function findOwned(\PDO $pdo, string $id, string $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        return $stmt->fetch();
    }
}
