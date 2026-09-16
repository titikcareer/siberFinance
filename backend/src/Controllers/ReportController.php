<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ReportController
{
    public function cashflow(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $granularity = $request->getQueryParams()['granularity'] ?? 'monthly'; // monthly|yearly
        $year = $request->getQueryParams()['year'] ?? date('Y');

        $format = $granularity === 'yearly' ? 'YYYY' : 'YYYY-MM';
        $range = $granularity === 'yearly'
            ? ["{$year}-01-01 00:00:00", "{$year}-12-31 23:59:59"]
            : ["{$year}-01-01 00:00:00", "{$year}-12-31 23:59:59"];

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT TO_CHAR(occurred_at, :fmt) AS bucket,
                    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
             FROM transactions
             WHERE user_id = :uid AND deleted_at IS NULL AND occurred_at BETWEEN :from AND :to
             GROUP BY bucket ORDER BY bucket ASC"
        );
        $stmt->execute(['fmt' => $format, 'uid' => $userId, 'from' => $range[0], 'to' => $range[1]]);
        $rows = $stmt->fetchAll();

        $data = array_map(fn ($r) => [
            'period' => $r['bucket'],
            'income' => (float) $r['income'],
            'expense' => (float) $r['expense'],
            'net' => (float) $r['income'] - (float) $r['expense'],
        ], $rows);

        return JsonResponse::ok($response, $data);
    }

    public function expenseBreakdown(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $params = $request->getQueryParams();
        $from = $params['from'] ?? date('Y-m-01');
        $to = $params['to'] ?? date('Y-m-t');

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT c.id AS category_id, c.name AS category_name, c.color, SUM(t.amount) AS total
             FROM transactions t
             JOIN categories c ON c.id = t.category_id
             WHERE t.user_id = :uid AND t.type = 'expense' AND t.deleted_at IS NULL
               AND t.occurred_at BETWEEN :from AND :to
             GROUP BY c.id, c.name, c.color
             ORDER BY total DESC"
        );
        $stmt->execute(['uid' => $userId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59']);

        return JsonResponse::ok($response, $stmt->fetchAll());
    }

    /**
     * Fitur Inovatif: Contextual Expense Micro-Journaling - correlation insight
     * Contoh output: "80% pengeluaran impulsif Anda terjadi saat stress tinggi di akhir pekan"
     */
    public function journalInsights(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $params = $request->getQueryParams();
        $from = $params['from'] ?? date('Y-m-01');
        $to = $params['to'] ?? date('Y-m-t');

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT t.amount, t.occurred_at, tc.stress_level, tc.urgency_level, tc.social_situation, tc.mood_tag
             FROM transactions t
             JOIN transaction_contexts tc ON tc.transaction_id = t.id
             WHERE t.user_id = :uid AND t.type = 'expense' AND t.deleted_at IS NULL
               AND t.occurred_at BETWEEN :from AND :to"
        );
        $stmt->execute(['uid' => $userId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59']);
        $rows = $stmt->fetchAll();

        $totalAmount = 0.0;
        $highStressWeekendAmount = 0.0;
        $stressBuckets = [];

        foreach ($rows as $r) {
            $amount = (float) $r['amount'];
            $totalAmount += $amount;

            $dow = (int) date('N', strtotime($r['occurred_at'])); // 6=Sabtu, 7=Minggu
            $isWeekend = $dow >= 6;
            $isHighStress = ((int) $r['stress_level']) >= 4;

            if ($isHighStress && $isWeekend) {
                $highStressWeekendAmount += $amount;
            }

            $level = $r['stress_level'] ?? 'unknown';
            $stressBuckets[$level] = ($stressBuckets[$level] ?? 0) + $amount;
        }

        $pctHighStressWeekend = $totalAmount > 0 ? round(($highStressWeekendAmount / $totalAmount) * 100, 1) : 0;

        return JsonResponse::ok($response, [
            'total_expense_with_context' => $totalAmount,
            'pct_high_stress_weekend_spending' => $pctHighStressWeekend,
            'breakdown_by_stress_level' => $stressBuckets,
            'insight_text' => $pctHighStressWeekend > 0
                ? "{$pctHighStressWeekend}% pengeluaran Anda (dengan catatan konteks) terjadi saat stres tinggi di akhir pekan."
                : 'Belum cukup data konteks untuk menghasilkan insight yang bermakna.',
        ]);
    }
}
