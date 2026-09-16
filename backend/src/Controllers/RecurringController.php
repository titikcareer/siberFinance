<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\AuditLogger;
use App\Services\RecurringTransactionService;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RecurringController
{
    public function __construct(private RecurringTransactionService $generator)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT r.*, c.name AS category_name, a.name AS account_name
             FROM recurring_transactions r
             LEFT JOIN categories c ON c.id = r.category_id
             LEFT JOIN accounts a ON a.id = r.account_id
             WHERE r.user_id = :uid ORDER BY r.next_run_date ASC'
        );
        $stmt->execute(['uid' => $userId]);

        return JsonResponse::ok($response, $stmt->fetchAll());
    }

    public function store(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = (array) $request->getParsedBody();

        $type = (string) ($body['type'] ?? '');
        $accountId = (string) ($body['account_id'] ?? '');
        $amount = (float) ($body['amount'] ?? 0);
        $frequency = (string) ($body['frequency'] ?? 'monthly');
        $startDate = (string) ($body['start_date'] ?? date('Y-m-d'));

        if (!in_array($type, ['income', 'expense', 'transfer'], true) || $accountId === '' || $amount <= 0) {
            return JsonResponse::error($response, 'type, account_id, dan amount (>0) wajib valid.', 422);
        }
        if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
            return JsonResponse::error($response, 'frequency tidak valid.', 422);
        }

        $id = AuditLogger::uuid();
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO recurring_transactions
                (id, user_id, account_id, category_id, type, amount, description, frequency, interval_count, start_date, end_date, next_run_date, created_at)
             VALUES
                (:id, :uid, :account_id, :category_id, :type, :amount, :desc, :freq, :interval, :start, :end, :next, NOW())'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'account_id' => $accountId,
            'category_id' => $body['category_id'] ?? null,
            'type' => $type,
            'amount' => $amount,
            'desc' => $body['description'] ?? null,
            'freq' => $frequency,
            'interval' => (int) ($body['interval_count'] ?? 1),
            'start' => $startDate,
            'end' => $body['end_date'] ?? null,
            'next' => $startDate, // Generate pertama kali persis di start_date
        ]);

        AuditLogger::log($userId, 'create', 'recurring_transaction', $id, null, $body);

        return JsonResponse::ok($response, ['id' => $id], 201);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = $args['id'];
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT * FROM recurring_transactions WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return JsonResponse::error($response, 'Template recurring tidak ditemukan.', 404);
        }

        $del = $pdo->prepare('UPDATE recurring_transactions SET is_active = 0 WHERE id = :id');
        $del->execute(['id' => $id]);

        AuditLogger::log($userId, 'delete', 'recurring_transaction', $id, $existing, null);

        return JsonResponse::ok($response, ['deactivated' => true]);
    }

    /**
     * Memicu generator untuk membuat transaksi dari template yang sudah jatuh tempo.
     * Idealnya dipanggil oleh scheduler (cron) harian; disediakan juga sebagai
     * endpoint agar bisa dipicu manual atau otomatis saat user membuka dashboard.
     */
    public function generateDue(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $generatedIds = $this->generator->generateDueForUser($userId);

        return JsonResponse::ok($response, [
            'generated_count' => count($generatedIds),
            'transaction_ids' => $generatedIds,
        ]);
    }
}
