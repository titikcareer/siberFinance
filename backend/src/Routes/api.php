<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\BudgetController;
use App\Controllers\CategoryController;
use App\Controllers\MicroBudgetController;
use App\Controllers\RecurringController;
use App\Controllers\ReportController;
use App\Controllers\SimulatorController;
use App\Controllers\SyncController;
use App\Controllers\TransactionController;
use App\Middleware\JwtAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    // --- Health check (health-check path PaaS / uptime monitoring) ---
    $app->get('/api/v1/health', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(['status' => 'ok', 'time' => gmdate('c')]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // --- Public routes ---
    $app->group('/api/v1/auth', function (RouteCollectorProxy $group) {
        $group->post('/register', [AuthController::class, 'register']);
        $group->post('/login', [AuthController::class, 'login']);
        $group->post('/refresh', [AuthController::class, 'refresh']);
    });

    // --- Protected routes (JWT wajib) ---
    $app->group('/api/v1', function (RouteCollectorProxy $group) {
        $group->get('/auth/me', [AuthController::class, 'me']);

        // Manajemen Aset & Akun
        $group->get('/accounts', [AccountController::class, 'index']);
        $group->post('/accounts', [AccountController::class, 'store']);
        $group->put('/accounts/{id}', [AccountController::class, 'update']);
        $group->delete('/accounts/{id}', [AccountController::class, 'destroy']);
        $group->get('/accounts/net-worth', [AccountController::class, 'netWorth']);

        // Kategorisasi
        $group->get('/categories', [CategoryController::class, 'index']);
        $group->post('/categories', [CategoryController::class, 'store']);
        $group->put('/categories/{id}', [CategoryController::class, 'update']);
        $group->delete('/categories/{id}', [CategoryController::class, 'destroy']);

        // Pencatatan Transaksi
        $group->get('/transactions', [TransactionController::class, 'index']);
        $group->post('/transactions', [TransactionController::class, 'store']);
        $group->delete('/transactions/{id}', [TransactionController::class, 'destroy']);

        // Recurring Transactions (tagihan bulanan, cicilan, dsb)
        $group->get('/recurring-transactions', [RecurringController::class, 'index']);
        $group->post('/recurring-transactions', [RecurringController::class, 'store']);
        $group->delete('/recurring-transactions/{id}', [RecurringController::class, 'destroy']);
        $group->post('/recurring-transactions/generate-due', [RecurringController::class, 'generateDue']);

        // Budgeting & Alert Limit
        $group->get('/budgets', [BudgetController::class, 'index']);
        $group->post('/budgets', [BudgetController::class, 'store']);
        $group->put('/budgets/{id}', [BudgetController::class, 'update']);
        $group->delete('/budgets/{id}', [BudgetController::class, 'destroy']);
        $group->get('/budgets/status', [BudgetController::class, 'statusForMonth']);
        $group->post('/budgets/apply-50-30-20', [BudgetController::class, 'applyFiftyThirtyTwentyRule']);

        // Laporan & Analytics
        $group->get('/reports/cashflow', [ReportController::class, 'cashflow']);
        $group->get('/reports/expense-breakdown', [ReportController::class, 'expenseBreakdown']);
        $group->get('/reports/journal-insights', [ReportController::class, 'journalInsights']);

        // Keamanan: Audit Trail
        $group->get('/audit-logs', [AuditController::class, 'index']);

        // === Fitur Inovatif ===
        // 1. Predictive What-If Financial Simulator
        $group->post('/simulator/run', [SimulatorController::class, 'run']);
        $group->get('/simulator/history', [SimulatorController::class, 'history']);

        // 2. Micro-Budgeting Dynamic Allocation Engine
        $group->post('/budgets/{budgetId}/recalculate-allocation', [MicroBudgetController::class, 'recalculate']);

        // 3. Offline-First Differential Sync
        $group->post('/sync/push', [SyncController::class, 'push']);
        $group->get('/sync/pull', [SyncController::class, 'pull']);
    })->add(JwtAuthMiddleware::class);
};
