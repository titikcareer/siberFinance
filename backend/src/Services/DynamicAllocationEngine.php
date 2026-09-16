<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

/**
 * Fitur Inovatif: Micro-Budgeting Dynamic Allocation Engine.
 *
 * Alih-alih limit harian statis (amount_limit / jumlah_hari), engine ini:
 *  1. Menghitung limit dasar harian (base_daily_limit).
 *  2. Melihat sisa (surplus) atau kekurangan (defisit) dari hari-hari sebelumnya
 *     dalam bulan berjalan.
 *  3. Membagi surplus/defisit tersebut secara pro-rata ke SISA hari dalam bulan,
 *     sehingga limit hari ini & seterusnya otomatis menyesuaikan.
 */
final class DynamicAllocationEngine
{
    public function recalculateForBudget(string $budgetId, string $userId): array
    {
        $pdo = Database::connection();

        $budgetStmt = $pdo->prepare('SELECT * FROM budgets WHERE id = :id AND user_id = :uid');
        $budgetStmt->execute(['id' => $budgetId, 'uid' => $userId]);
        $budget = $budgetStmt->fetch();

        if (!$budget) {
            throw new \RuntimeException('Budget tidak ditemukan.');
        }

        [$year, $month] = array_map('intval', explode('-', $budget['period_month']));
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $baseDailyLimit = (float) $budget['amount_limit'] / $daysInMonth;

        $today = (int) date('j');
        $currentDay = min($today, $daysInMonth);

        $carryOver = 0.0;
        $allocations = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

            $remainingDaysFromToday = max(1, $daysInMonth - $day + 1);
            $adjustedLimit = $baseDailyLimit + ($carryOver / $remainingDaysFromToday);

            $spent = $this->getSpentOnDate($pdo, $userId, $date, $budget['category_id']);

            if ($day <= $currentDay) {
                // Hari yang sudah lewat (atau hari ini): hitung surplus/defisit aktualnya
                $dailyDelta = $adjustedLimit - $spent;
                $carryOver = $day < $currentDay ? $dailyDelta : 0.0; // surplus hari ini baru dibagi besok
            }

            $allocations[] = [
                'date' => $date,
                'base_daily_limit' => round($baseDailyLimit, 2),
                'adjusted_daily_limit' => round($adjustedLimit, 2),
                'spent' => round($spent, 2),
                'carry_over_applied' => round($carryOver, 2),
            ];

            $this->persistAllocation($pdo, $budgetId, $date, $baseDailyLimit, $adjustedLimit, $spent, $carryOver);
        }

        return $allocations;
    }

    private function getSpentOnDate(\PDO $pdo, string $userId, string $date, ?string $categoryId): float
    {
        $sql = "SELECT COALESCE(SUM(amount), 0) AS total FROM transactions
                WHERE user_id = :uid AND type = 'expense' AND deleted_at IS NULL
                  AND occurred_at::date = :date";
        $bind = ['uid' => $userId, 'date' => $date];

        if ($categoryId) {
            $sql .= ' AND category_id = :category_id';
            $bind['category_id'] = $categoryId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        return (float) $stmt->fetch()['total'];
    }

    private function persistAllocation(
        \PDO $pdo,
        string $budgetId,
        string $date,
        float $base,
        float $adjusted,
        float $spent,
        float $carryOver
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO budget_daily_allocations (id, budget_id, allocation_date, base_daily_limit, adjusted_daily_limit, spent_amount, carry_over_from_prev, created_at)
             VALUES (:id, :budget_id, :date, :base, :adjusted, :spent, :carry, NOW())
             ON CONFLICT (budget_id, allocation_date) DO UPDATE SET
                adjusted_daily_limit = EXCLUDED.adjusted_daily_limit,
                spent_amount = EXCLUDED.spent_amount,
                carry_over_from_prev = EXCLUDED.carry_over_from_prev'
        );
        $stmt->execute([
            'id' => AuditLogger::uuid(),
            'budget_id' => $budgetId,
            'date' => $date,
            'base' => $base,
            'adjusted' => $adjusted,
            'spent' => $spent,
            'carry' => $carryOver,
        ]);
    }
}
