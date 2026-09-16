<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\AuditLogger;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TransactionController
{
    public function index(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $params = $request->getQueryParams();

        $from = $params['from'] ?? date('Y-m-01');
        $to = $params['to'] ?? date('Y-m-t');
        $accountId = $params['account_id'] ?? null;
        $categoryId = $params['category_id'] ?? null;

        $sql = 'SELECT t.*, c.name AS category_name, a.name AS account_name, tc.stress_level, tc.urgency_level, tc.social_situation, tc.mood_tag, tc.note AS context_note
                FROM transactions t
                LEFT JOIN categories c ON c.id = t.category_id
                LEFT JOIN accounts a ON a.id = t.account_id
                LEFT JOIN transaction_contexts tc ON tc.transaction_id = t.id
                WHERE t.user_id = :uid AND t.deleted_at IS NULL
                  AND t.occurred_at BETWEEN :from AND :to';
        $bind = ['uid' => $userId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];

        if ($accountId) {
            $sql .= ' AND t.account_id = :account_id';
            $bind['account_id'] = $accountId;
        }
        if ($categoryId) {
            $sql .= ' AND t.category_id = :category_id';
            $bind['category_id'] = $categoryId;
        }
        $sql .= ' ORDER BY t.occurred_at DESC LIMIT 500';

        $pdo = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        return JsonResponse::ok($response, $stmt->fetchAll());
    }

    public function store(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = (array) $request->getParsedBody();

        $type = (string) ($body['type'] ?? '');
        $accountId = (string) ($body['account_id'] ?? '');
        $amount = (float) ($body['amount'] ?? 0);
        $occurredAt = (string) ($body['occurred_at'] ?? date('Y-m-d H:i:s'));

        if (!in_array($type, ['income', 'expense', 'transfer'], true) || $accountId === '' || $amount <= 0) {
            return JsonResponse::error($response, 'type, account_id, dan amount (>0) wajib valid.', 422);
        }

        if ($type === 'transfer' && empty($body['transfer_to_account_id'])) {
            return JsonResponse::error($response, 'transfer_to_account_id wajib diisi untuk transaksi transfer.', 422);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $id = AuditLogger::uuid();

            $stmt = $pdo->prepare(
                'INSERT INTO transactions
                    (id, user_id, account_id, category_id, type, amount, transfer_to_account_id, description, occurred_at, recurring_id, client_uuid, created_at, updated_at)
                 VALUES
                    (:id, :uid, :account_id, :category_id, :type, :amount, :transfer_to, :desc, :occurred_at, :recurring_id, :client_uuid, NOW(), NOW())'
            );
            $stmt->execute([
                'id' => $id,
                'uid' => $userId,
                'account_id' => $accountId,
                'category_id' => $body['category_id'] ?? null,
                'type' => $type,
                'amount' => $amount,
                'transfer_to' => $body['transfer_to_account_id'] ?? null,
                'desc' => $body['description'] ?? null,
                'occurred_at' => $occurredAt,
                'recurring_id' => $body['recurring_id'] ?? null,
                'client_uuid' => $body['client_uuid'] ?? null,
            ]);

            $this->applyBalanceEffect($pdo, $accountId, $type, $amount, $body['transfer_to_account_id'] ?? null);

            // Fitur Inovatif: Contextual Expense Micro-Journaling (opsional, hanya untuk expense)
            if ($type === 'expense' && !empty($body['context'])) {
                $ctx = $body['context'];
                $ctxStmt = $pdo->prepare(
                    'INSERT INTO transaction_contexts (id, transaction_id, stress_level, urgency_level, social_situation, mood_tag, note, created_at)
                     VALUES (:id, :tx_id, :stress, :urgency, :social, :mood, :note, NOW())'
                );
                $ctxStmt->execute([
                    'id' => AuditLogger::uuid(),
                    'tx_id' => $id,
                    'stress' => $ctx['stress_level'] ?? null,
                    'urgency' => $ctx['urgency_level'] ?? null,
                    'social' => $ctx['social_situation'] ?? null,
                    'mood' => $ctx['mood_tag'] ?? null,
                    'note' => $ctx['note'] ?? null,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return JsonResponse::error($response, 'Gagal menyimpan transaksi: ' . $e->getMessage(), 500);
        }

        AuditLogger::log($userId, 'create', 'transaction', $id, null, $body);

        return JsonResponse::ok($response, ['id' => $id], 201);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        $txId = $args['id'];
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = :id AND user_id = :uid AND deleted_at IS NULL');
        $stmt->execute(['id' => $txId, 'uid' => $userId]);
        $tx = $stmt->fetch();

        if (!$tx) {
            return JsonResponse::error($response, 'Transaksi tidak ditemukan.', 404);
        }

        $pdo->beginTransaction();
        try {
            // Reverse efek saldo, lalu soft-delete (tombstone) agar bisa di-sync sebagai delete
            $this->reverseBalanceEffect($pdo, $tx);

            $del = $pdo->prepare('UPDATE transactions SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id');
            $del->execute(['id' => $txId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return JsonResponse::error($response, 'Gagal menghapus transaksi: ' . $e->getMessage(), 500);
        }

        AuditLogger::log($userId, 'delete', 'transaction', $txId, $tx, null);

        return JsonResponse::ok($response, ['deleted' => true]);
    }

    private function applyBalanceEffect(\PDO $pdo, string $accountId, string $type, float $amount, ?string $transferTo): void
    {
        if ($type === 'income') {
            $this->adjustBalance($pdo, $accountId, $amount);
        } elseif ($type === 'expense') {
            $this->adjustBalance($pdo, $accountId, -$amount);
        } elseif ($type === 'transfer' && $transferTo) {
            $this->adjustBalance($pdo, $accountId, -$amount);
            $this->adjustBalance($pdo, $transferTo, $amount);
        }
    }

    private function reverseBalanceEffect(\PDO $pdo, array $tx): void
    {
        $type = $tx['type'];
        $amount = (float) $tx['amount'];
        if ($type === 'income') {
            $this->adjustBalance($pdo, $tx['account_id'], -$amount);
        } elseif ($type === 'expense') {
            $this->adjustBalance($pdo, $tx['account_id'], $amount);
        } elseif ($type === 'transfer' && $tx['transfer_to_account_id']) {
            $this->adjustBalance($pdo, $tx['account_id'], $amount);
            $this->adjustBalance($pdo, $tx['transfer_to_account_id'], -$amount);
        }
    }

    private function adjustBalance(\PDO $pdo, string $accountId, float $delta): void
    {
        $stmt = $pdo->prepare('UPDATE accounts SET balance = balance + :delta, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['delta' => $delta, 'id' => $accountId]);
    }
}
