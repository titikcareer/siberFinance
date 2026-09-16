<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\AuditLogger;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BudgetController
{
    public function index(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $period = $request->getQueryParams()['period'] ?? date('Y-m');

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT b.*, c.name AS category_name FROM budgets b
             LEFT JOIN categories c ON c.id = b.category_id
             WHERE b.user_id = :uid AND b.period_month = :period ORDER BY b.created_at ASC'
        );
        $stmt->execute(['uid' => $userId, 'period' => $period]);

        return JsonResponse::ok($response, $stmt->fetchAll());
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = $args['id'];
        $body = (array) $request->getParsedBody();

        $pdo = Database::connection();
        $existing = $this->findOwned($pdo, $id, $userId);
        if (!$existing) {
            return JsonResponse::error($response, 'Budget tidak ditemukan.', 404);
        }

        $amountLimit = isset($body['amount_limit']) ? (float) $body['amount_limit'] : (float) $existing['amount_limit'];
        if ($amountLimit <= 0) {
            return JsonResponse::error($response, 'amount_limit wajib > 0.', 422);
        }

        $stmt = $pdo->prepare('UPDATE budgets SET amount_limit = :limit WHERE id = :id');
        $stmt->execute(['limit' => $amountLimit, 'id' => $id]);

        AuditLogger::log($userId, 'update', 'budget', $id, $existing, $body);

        return JsonResponse::ok($response, ['id' => $id]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = $args['id'];
        $pdo = Database::connection();

        $existing = $this->findOwned($pdo, $id, $userId);
        if (!$existing) {
            return JsonResponse::error($response, 'Budget tidak ditemukan.', 404);
        }

        $stmt = $pdo->prepare('DELETE FROM budgets WHERE id = :id');
        $stmt->execute(['id' => $id]);

        AuditLogger::log($userId, 'delete', 'budget', $id, $existing, null);

        return JsonResponse::ok($response, ['deleted' => true]);
    }

    /**
     * Auto-split Rule 50/30/20: berdasarkan rata-rata pemasukan 3 bulan terakhir,
     * otomatis membuat 3 budget grup (Needs 50%, Wants 30%, Savings 20%) untuk
     * periode yang diminta. Kategori individual tetap bisa dibuat manual via store().
     */
    public function applyFiftyThirtyTwentyRule(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = (array) $request->getParsedBody();
        $period = (string) ($body['period_month'] ?? date('Y-m'));

        $pdo = Database::connection();
        $incomeStmt = $pdo->prepare(
            "SELECT COALESCE(AVG(monthly.income), 0) AS avg_income FROM (
                SELECT TO_CHAR(occurred_at, 'YYYY-MM') AS ym, SUM(amount) AS income
                FROM transactions
                WHERE user_id = :uid AND type = 'income' AND deleted_at IS NULL
                  AND occurred_at >= NOW() - INTERVAL '3 months'
                GROUP BY ym
             ) AS monthly"
        );
        $incomeStmt->execute(['uid' => $userId]);
        $avgIncome = (float) $incomeStmt->fetch()['avg_income'];

        if ($avgIncome <= 0) {
            return JsonResponse::error($response, 'Belum ada data pemasukan yang cukup untuk menghitung rule 50/30/20. Catat transaksi pemasukan terlebih dahulu.', 422);
        }

        $groups = [
            'needs' => round($avgIncome * 0.5, 2),
            'wants' => round($avgIncome * 0.3, 2),
            'savings' => round($avgIncome * 0.2, 2),
        ];

        $created = [];
        foreach ($groups as $label => $amount) {
            $id = AuditLogger::uuid();
            $stmt = $pdo->prepare(
                'INSERT INTO budgets (id, user_id, category_id, group_label, period_month, amount_limit, rule_type, created_at)
                 VALUES (:id, :uid, NULL, :label, :period, :amount, \'50_30_20\', NOW())'
            );
            $stmt->execute(['id' => $id, 'uid' => $userId, 'label' => $label, 'period' => $period, 'amount' => $amount]);
            $created[] = ['id' => $id, 'group_label' => $label, 'amount_limit' => $amount];
        }

        AuditLogger::log($userId, 'create', 'budget', null, null, ['rule' => '50_30_20', 'period' => $period, 'based_on_avg_income' => $avgIncome]);

        return JsonResponse::ok($response, ['based_on_avg_income' => $avgIncome, 'budgets' => $created], 201);
    }

    private function findOwned(\PDO $pdo, string $id, string $userId): array|false
    {
        $stmt = $pdo->prepare('SELECT * FROM budgets WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        return $stmt->fetch();
    }

    public function store(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = (array) $request->getParsedBody();

        $periodMonth = (string) ($body['period_month'] ?? date('Y-m'));
        $amountLimit = (float) ($body['amount_limit'] ?? 0);
        $ruleType = (string) ($body['rule_type'] ?? 'envelope');

        if ($amountLimit <= 0) {
            return JsonResponse::error($response, 'amount_limit wajib > 0.', 422);
        }

        $id = AuditLogger::uuid();
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO budgets (id, user_id, category_id, group_label, period_month, amount_limit, rule_type, created_at)
             VALUES (:id, :uid, :category_id, :group_label, :period, :limit, :rule, NOW())'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'category_id' => $body['category_id'] ?? null,
            'group_label' => $body['group_label'] ?? null,
            'period' => $periodMonth,
            'limit' => $amountLimit,
            'rule' => $ruleType,
        ]);

        AuditLogger::log($userId, 'create', 'budget', $id, null, $body);

        return JsonResponse::ok($response, ['id' => $id], 201);
    }

    /**
     * Menampilkan seluruh budget bulan berjalan beserta status pemakaian & threshold alert.
     * threshold: ok (<80%), warning (80-99%), exceeded (>=100%)
     */
    public function statusForMonth(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $period = $request->getQueryParams()['period'] ?? date('Y-m');

        $pdo = Database::connection();
        $budgets = $pdo->prepare('SELECT * FROM budgets WHERE user_id = :uid AND period_month = :period');
        $budgets->execute(['uid' => $userId, 'period' => $period]);
        $budgetRows = $budgets->fetchAll();

        $result = [];
        foreach ($budgetRows as $b) {
            $spent = $this->calculateSpent($pdo, $userId, $period, $b['category_id']);
            $pct = $b['amount_limit'] > 0 ? ($spent / (float) $b['amount_limit']) * 100 : 0;

            $status = 'ok';
            if ($pct >= 100) {
                $status = 'exceeded';
            } elseif ($pct >= 80) {
                $status = 'warning';
            }

            $result[] = [
                'budget_id' => $b['id'],
                'category_id' => $b['category_id'],
                'group_label' => $b['group_label'],
                'rule_type' => $b['rule_type'],
                'amount_limit' => (float) $b['amount_limit'],
                'spent' => $spent,
                'remaining' => (float) $b['amount_limit'] - $spent,
                'percentage_used' => round($pct, 1),
                'status' => $status,
            ];
        }

        return JsonResponse::ok($response, $result);
    }

    private function calculateSpent(\PDO $pdo, string $userId, string $period, ?string $categoryId): float
    {
        $sql = "SELECT COALESCE(SUM(amount), 0) AS total FROM transactions
                WHERE user_id = :uid AND type = 'expense' AND deleted_at IS NULL
                  AND TO_CHAR(occurred_at, 'YYYY-MM') = :period";
        $bind = ['uid' => $userId, 'period' => $period];

        if ($categoryId) {
            $sql .= ' AND category_id = :category_id';
            $bind['category_id'] = $categoryId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        return (float) $stmt->fetch()['total'];
    }
}
