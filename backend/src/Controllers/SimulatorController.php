<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\AuditLogger;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Fitur Inovatif: Predictive "What-If" Financial Simulator.
 *
 * User memasukkan asumsi perubahan (misal: cicilan baru, kenaikan gaji, biaya
 * sekali-waktu), lalu sistem memproyeksikan cashflow & saldo bulanan hingga
 * `horizon_months` ke depan berdasarkan rata-rata historis 3 bulan terakhir
 * sebagai baseline.
 */
final class SimulatorController
{
    public function run(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = (array) $request->getParsedBody();

        $horizon = (int) ($body['horizon_months'] ?? 60);
        $assumptions = $body['assumptions'] ?? [];
        // assumptions contoh:
        // [{ "label": "Cicilan Motor", "type": "recurring_expense", "amount": 1500000, "start_month": 1, "duration_months": 24 },
        //  { "label": "Kenaikan Gaji", "type": "recurring_income_delta", "amount": 1000000, "start_month": 3, "duration_months": null }]

        $baseline = $this->getBaseline($userId);

        $projection = [];
        $runningBalance = $baseline['current_liquid_balance'];

        for ($month = 1; $month <= $horizon; $month++) {
            $income = $baseline['avg_monthly_income'];
            $expense = $baseline['avg_monthly_expense'];

            foreach ($assumptions as $a) {
                $start = (int) ($a['start_month'] ?? 1);
                $duration = $a['duration_months'] ?? null;
                $active = $month >= $start && ($duration === null || $month < $start + (int) $duration);

                if (!$active) {
                    continue;
                }

                $amount = (float) ($a['amount'] ?? 0);
                match ($a['type'] ?? '') {
                    'recurring_expense' => $expense += $amount,
                    'recurring_income_delta' => $income += $amount,
                    'one_time_expense' => $month === $start ? $expense += $amount : null,
                    default => null,
                };
            }

            $net = $income - $expense;
            $runningBalance += $net;

            $projection[] = [
                'month' => $month,
                'projected_income' => round($income, 2),
                'projected_expense' => round($expense, 2),
                'net_cashflow' => round($net, 2),
                'projected_balance' => round($runningBalance, 2),
                // Runway = berapa bulan lagi saldo bertahan jika net cashflow negatif terus
                'risk_level' => $this->riskLevel($runningBalance, $baseline['avg_monthly_expense']),
            ];
        }

        $runwayMonths = $this->calculateRunway($projection, $baseline['avg_monthly_expense']);

        $result = [
            'baseline' => $baseline,
            'projection' => $projection,
            'savings_runway_months' => $runwayMonths,
        ];

        $pdo = Database::connection();
        $scenarioId = AuditLogger::uuid();
        $stmt = $pdo->prepare(
            'INSERT INTO what_if_scenarios (id, user_id, name, assumptions_json, projection_json, horizon_months, created_at)
             VALUES (:id, :uid, :name, :assumptions, :projection, :horizon, NOW())'
        );
        $stmt->execute([
            'id' => $scenarioId,
            'uid' => $userId,
            'name' => $body['name'] ?? 'Skenario ' . date('Y-m-d H:i'),
            'assumptions' => json_encode($assumptions),
            'projection' => json_encode($result),
            'horizon' => $horizon,
        ]);

        $result['scenario_id'] = $scenarioId;

        return JsonResponse::ok($response, $result);
    }

    public function history(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, name, horizon_months, created_at FROM what_if_scenarios WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50'
        );
        $stmt->execute(['uid' => $userId]);

        return JsonResponse::ok($response, $stmt->fetchAll());
    }

    private function getBaseline(string $userId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(AVG(monthly.income), 0) AS avg_income,
                COALESCE(AVG(monthly.expense), 0) AS avg_expense
             FROM (
                SELECT TO_CHAR(occurred_at, 'YYYY-MM') AS ym,
                       SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                       SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
                FROM transactions
                WHERE user_id = :uid AND deleted_at IS NULL
                  AND occurred_at >= NOW() - INTERVAL '3 months'
                GROUP BY ym
             ) AS monthly"
        );
        $stmt->execute(['uid' => $userId]);
        $avg = $stmt->fetch();

        $balanceStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(balance), 0) AS total FROM accounts
             WHERE user_id = :uid AND is_archived = 0 AND type IN ('bank','cash','ewallet')"
        );
        $balanceStmt->execute(['uid' => $userId]);
        $liquid = $balanceStmt->fetch();

        return [
            'avg_monthly_income' => round((float) $avg['avg_income'], 2),
            'avg_monthly_expense' => round((float) $avg['avg_expense'], 2),
            'current_liquid_balance' => round((float) $liquid['total'], 2),
        ];
    }

    private function riskLevel(float $balance, float $avgExpense): string
    {
        if ($balance <= 0) {
            return 'critical';
        }
        if ($avgExpense > 0 && $balance < $avgExpense * 1) {
            return 'high';
        }
        if ($avgExpense > 0 && $balance < $avgExpense * 3) {
            return 'medium';
        }
        return 'low';
    }

    private function calculateRunway(array $projection, float $avgExpense): ?int
    {
        foreach ($projection as $p) {
            if ($p['projected_balance'] <= 0) {
                return $p['month'];
            }
        }
        return null; // Aman selama horizon yang disimulasikan
    }
}
