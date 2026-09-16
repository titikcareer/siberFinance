<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

/**
 * RecurringTransactionService
 *
 * Bertanggung jawab menghasilkan transaksi nyata (tabel `transactions`) dari
 * template di `recurring_transactions` yang sudah jatuh tempo (next_run_date <= hari ini).
 *
 * Dipanggil dari dua tempat:
 *  1. RecurringController::generateDue() - dipicu manual/oleh scheduler (cron) via API.
 *  2. Middleware ringan saat user login / membuka dashboard (opsional, lihat catatan di README).
 */
final class RecurringTransactionService
{
    public function generateDueForUser(string $userId): array
    {
        $pdo = Database::connection();
        $today = date('Y-m-d');

        $stmt = $pdo->prepare(
            'SELECT * FROM recurring_transactions
             WHERE user_id = :uid AND is_active = 1 AND next_run_date <= :today
               AND (end_date IS NULL OR end_date >= next_run_date)'
        );
        $stmt->execute(['uid' => $userId, 'today' => $today]);
        $dueTemplates = $stmt->fetchAll();

        $generated = [];

        foreach ($dueTemplates as $tpl) {
            // Loop selama next_run_date masih <= hari ini, agar tagihan yang "terlewat"
            // (mis. user tidak buka app selama 2 bulan) tetap tercatat lengkap.
            while ($tpl['next_run_date'] <= $today && $tpl['is_active']) {
                $txId = $this->createTransactionFromTemplate($pdo, $tpl);
                $generated[] = $txId;

                $nextRunDate = $this->calculateNextRunDate($tpl['next_run_date'], $tpl['frequency'], (int) $tpl['interval_count']);

                if ($tpl['end_date'] !== null && $nextRunDate > $tpl['end_date']) {
                    $this->deactivateTemplate($pdo, $tpl['id']);
                    $tpl['is_active'] = 0;
                    break;
                }

                $this->updateNextRunDate($pdo, $tpl['id'], $nextRunDate);
                $tpl['next_run_date'] = $nextRunDate;
            }
        }

        return $generated;
    }

    private function createTransactionFromTemplate(\PDO $pdo, array $tpl): string
    {
        $id = AuditLogger::uuid();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO transactions
                    (id, user_id, account_id, category_id, type, amount, description, occurred_at, recurring_id, created_at, updated_at)
                 VALUES
                    (:id, :uid, :account_id, :category_id, :type, :amount, :desc, :occurred_at, :recurring_id, NOW(), NOW())'
            );
            $stmt->execute([
                'id' => $id,
                'uid' => $tpl['user_id'],
                'account_id' => $tpl['account_id'],
                'category_id' => $tpl['category_id'],
                'type' => $tpl['type'],
                'amount' => $tpl['amount'],
                'desc' => ($tpl['description'] ?? 'Transaksi berulang') . ' (auto-generated)',
                'occurred_at' => $tpl['next_run_date'] . ' 00:00:00',
                'recurring_id' => $tpl['id'],
            ]);

            $delta = $tpl['type'] === 'income' ? (float) $tpl['amount'] : -(float) $tpl['amount'];
            $balanceStmt = $pdo->prepare('UPDATE accounts SET balance = balance + :delta, updated_at = NOW() WHERE id = :id');
            $balanceStmt->execute(['delta' => $delta, 'id' => $tpl['account_id']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditLogger::log($tpl['user_id'], 'create', 'transaction', $id, null, [
            'source' => 'recurring_generator',
            'recurring_id' => $tpl['id'],
        ]);

        return $id;
    }

    private function calculateNextRunDate(string $currentDate, string $frequency, int $intervalCount): string
    {
        $modifierMap = [
            'daily' => "+{$intervalCount} day",
            'weekly' => "+{$intervalCount} week",
            'monthly' => "+{$intervalCount} month",
            'yearly' => "+{$intervalCount} year",
        ];
        $modifier = $modifierMap[$frequency] ?? "+{$intervalCount} month";

        return date('Y-m-d', strtotime($currentDate . ' ' . $modifier));
    }

    private function updateNextRunDate(\PDO $pdo, string $templateId, string $nextRunDate): void
    {
        $stmt = $pdo->prepare('UPDATE recurring_transactions SET next_run_date = :next WHERE id = :id');
        $stmt->execute(['next' => $nextRunDate, 'id' => $templateId]);
    }

    private function deactivateTemplate(\PDO $pdo, string $templateId): void
    {
        $stmt = $pdo->prepare('UPDATE recurring_transactions SET is_active = 0 WHERE id = :id');
        $stmt->execute(['id' => $templateId]);
    }
}
