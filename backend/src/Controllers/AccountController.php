<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\AuditLogger;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AccountController
{
    private const VALID_TYPES = ['bank', 'cash', 'ewallet', 'investment', 'liability'];

    public function index(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM accounts WHERE user_id = :uid AND is_archived = 0 ORDER BY created_at ASC'
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
        $balance = (float) ($body['balance'] ?? 0);
        $currency = (string) ($body['currency'] ?? 'IDR');

        if ($name === '' || !in_array($type, self::VALID_TYPES, true)) {
            return JsonResponse::error($response, 'Nama akun dan tipe (bank|cash|ewallet|investment|liability) wajib valid.', 422);
        }

        $id = AuditLogger::uuid();
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO accounts (id, user_id, name, type, balance, currency, created_at, updated_at)
             VALUES (:id, :uid, :name, :type, :balance, :currency, NOW(), NOW())'
        );
        $stmt->execute([
            'id' => $id, 'uid' => $userId, 'name' => $name,
            'type' => $type, 'balance' => $balance, 'currency' => $currency,
        ]);

        AuditLogger::log($userId, 'create', 'account', $id, null, $body);

        return JsonResponse::ok($response, ['id' => $id], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = $args['id'];
        $body = (array) $request->getParsedBody();

        $pdo = Database::connection();
        $existing = $this->findOwned($pdo, $accountId, $userId);
        if (!$existing) {
            return JsonResponse::error($response, 'Akun tidak ditemukan.', 404);
        }

        $name = trim((string) ($body['name'] ?? $existing['name']));
        $balance = isset($body['balance']) ? (float) $body['balance'] : (float) $existing['balance'];

        $stmt = $pdo->prepare('UPDATE accounts SET name = :name, balance = :balance, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['name' => $name, 'balance' => $balance, 'id' => $accountId]);

        AuditLogger::log($userId, 'update', 'account', $accountId, $existing, $body);

        return JsonResponse::ok($response, ['id' => $accountId]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = $args['id'];
        $pdo = Database::connection();

        $existing = $this->findOwned($pdo, $accountId, $userId);
        if (!$existing) {
            return JsonResponse::error($response, 'Akun tidak ditemukan.', 404);
        }

        // Soft-archive, bukan hard delete, agar histori transaksi tetap konsisten
        $stmt = $pdo->prepare('UPDATE accounts SET is_archived = 1, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $accountId]);

        AuditLogger::log($userId, 'delete', 'account', $accountId, $existing, null);

        return JsonResponse::ok($response, ['archived' => true]);
    }

    /**
     * Net Worth = Total Aset (bank+cash+ewallet+investment) - Total Kewajiban (liability)
     */
    public function netWorth(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT type, COALESCE(SUM(balance), 0) AS total
             FROM accounts WHERE user_id = :uid AND is_archived = 0
             GROUP BY type"
        );
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();

        $totalsByType = array_column($rows, 'total', 'type');
        $assetTypes = ['bank', 'cash', 'ewallet', 'investment'];

        $totalAssets = 0.0;
        foreach ($assetTypes as $t) {
            $totalAssets += (float) ($totalsByType[$t] ?? 0);
        }
        $totalLiabilities = (float) ($totalsByType['liability'] ?? 0);

        return JsonResponse::ok($response, [
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'net_worth' => $totalAssets - $totalLiabilities,
            'breakdown' => $totalsByType,
        ]);
    }

    private function findOwned(\PDO $pdo, string $id, string $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        return $stmt->fetch();
    }
}
