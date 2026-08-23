<?php

use App\Http\Controllers\AdminFeeController;
use App\Http\Controllers\AdminOperationsActionController;
use App\Http\Controllers\AdminReadController;
use App\Http\Controllers\CashierActionController;
use App\Http\Controllers\CompanyLogoController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\TellerController;
use App\Http\Controllers\TellerFloatActionController;
use App\Http\Controllers\TransactionEntryController;
use App\Models\Transaction;
use App\Services\AdminOperationsDataService;
use App\Services\DailyReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health', fn () => response()->json(['status' => 'ok']))->name('health');
Route::get('/login', LoginController::class)->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
Route::middleware('auth')->post('/logout', [LoginController::class, 'logout'])->name('logout');
Route::middleware('auth')->get('/', [LoginController::class, 'home'])->name('home');
Route::middleware(['auth', 'role:admin'])->get('/reports/daily/pdf', function (Request $request, DailyReportService $reports) {
    $date = $request->query('date', now()->toDateString());
    $summary = $reports->summary((string) $date);
    $lines = [
        'Ngwe Lwe Daily Report',
        'Date: '.$summary['summary_date'],
        'Cash In: '.$summary['total_cash_in'],
        'Cash Out: '.$summary['total_cash_out'],
        'Transfer: '.$summary['total_transfer'],
        'Exchange: '.$summary['total_exchange'],
        'Commission: '.$summary['total_commission'],
        'Customer Fees: '.$summary['total_customer_fees'],
        'Total Profit: '.$summary['total_profit'],
        'Transactions: '.$summary['transaction_count'],
        'Main Vault: '.$summary['main_vault_total'],
        'Employee Floats: '.$summary['employee_floats_total'],
        'Total Cash: '.$summary['total_cash'],
        'Total Digital: '.$summary['total_digital'],
        'Grand Total: '.$summary['grand_total'],
    ];

    return response(minimal_pdf($lines), 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="daily-report-'.$summary['summary_date'].'.pdf"',
    ]);
})
    ->name('reports.daily.pdf');
Route::middleware('auth')->get('/dashboard', DashboardController::class)->name('dashboard');
Route::middleware('auth')
    ->get('/companies/{company}/logo', CompanyLogoController::class)
    ->name('companies.logo');
$adminSections = [
    'overview' => 'overview',
    'companies' => 'companies',
    'exchange-rates' => 'exchange-rates',
    'accounts' => 'accounts',
    'fees' => 'fees',
    'users' => 'users',
    'transactions' => 'transactions',
    'vault' => 'vault',
    'reports' => 'reports',
];
$adminCrudSections = [
    'companies',
    'exchange-rates',
    'accounts',
    'fees',
    'users',
];
$adminDetailSections = [
    ...$adminCrudSections,
    'transactions',
];
$adminPageComponents = [
    'overview' => 'admin/Overview',
    'companies' => 'admin/Companies',
    'exchange-rates' => 'admin/ExchangeRates',
    'accounts' => 'admin/Accounts',
    'fees' => 'admin/Fees',
    'users' => 'admin/Users',
    'vault' => 'admin/Vault',
    'reports' => 'admin/Reports',
];
$renderAdminOperations = static function (
    Request $request,
    string $section = 'overview',
    string $mode = 'list',
    ?int $resourceId = null,
    ?string $transactionSubsection = null,
) use ($adminSections, $adminPageComponents) {
    abort_unless(array_key_exists($section, $adminSections), 404);

    $component = $section === 'transactions'
        ? ($mode === 'detail'
            ? 'admin/transactions/Detail'
            : 'admin/transactions/ActivityLogs')
        : $adminPageComponents[$section];

    return Inertia::render($component, [
        'role' => $request->user()?->role,
        'section' => $adminSections[$section],
        'mode' => $mode,
        'resourceId' => $resourceId,
        'transactionSubsection' => $section === 'transactions'
            ? ($transactionSubsection ?? 'records')
            : null,
        'announcement' => 'Owner console for setup pages, reports, vault visibility and audit review.',
        'notificationCount' => Transaction::query()
            ->where('transaction_type', 'cash_in')
            ->where('status', 'PENDING_CASHIER_CONFIRM')
            ->count(),
        'adminData' => app(AdminOperationsDataService::class)->get($request),
    ]);
};
Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.operations')
    ->group(function () use ($adminSections, $adminCrudSections, $adminDetailSections, $renderAdminOperations): void {
        Route::get('/', fn (Request $request) => $renderAdminOperations($request))
            ->name('');
        Route::get('/overview', fn (Request $request) => $renderAdminOperations($request))
            ->name('.overview');
        Route::prefix('actions')->name('.actions.')->controller(AdminOperationsActionController::class)->group(function (): void {
            Route::post('/companies', 'storeCompany')->name('companies.store');
            Route::patch('/companies/{company}', 'updateCompany')->name('companies.update');
            Route::post('/companies/{company}', 'updateCompany')->name('companies.update-with-logo');
            Route::patch('/companies/{company}/status', 'toggleCompany')->name('companies.status');
            Route::delete('/companies/{company}', 'destroyCompany')->name('companies.destroy');
            Route::post('/accounts', 'storeAccount')->name('accounts.store');
            Route::patch('/accounts/{account}', 'updateAccount')->name('accounts.update');
            Route::patch('/accounts/{account}/status', 'toggleAccount')->name('accounts.status');
            Route::delete('/accounts/{account}', 'destroyAccount')->name('accounts.destroy');
            Route::post('/accounts/{account}/balance-adjust', 'adjustAccount')->name('accounts.balance-adjust');
            Route::post('/users', 'storeUser')->name('users.store');
            Route::patch('/users/{user}', 'updateUser')->name('users.update');
            Route::patch('/users/{user}/status', 'toggleUser')->name('users.status');
            Route::post('/users/{user}/reset-password', 'resetUserPassword')->name('users.reset-password');
            Route::post('/users/{user}/pin', 'setUserPin')->name('users.pin');
            Route::post('/exchange-rates', 'storeRate')->name('exchange-rates.store');
            Route::patch('/exchange-rates/{exchangeRate}', 'updateRate')->name('exchange-rates.update');
            Route::delete('/exchange-rates/{exchangeRate}', 'destroyRate')->name('exchange-rates.destroy');
            Route::post('/password', 'changePassword')->name('password');
            Route::post('/close-day', 'closeDay')->name('close-day');
            Route::post('/backup', 'backup')->name('backup');
            Route::post('/vault/entries', 'recordCashierVaultEntry')->name('vault.entries.store');
            Route::post('/broadcast-test', 'broadcastTest')->name('broadcast-test');
        });
        Route::get('/fees', [AdminFeeController::class, 'index'])->name('.fees');
        Route::get('/fees/create', [AdminFeeController::class, 'createProvider'])->name('.fees.create');
        Route::get('/fees/provider', [AdminFeeController::class, 'provider'])->name('.fees.provider');
        Route::get('/fees/provider/create', [AdminFeeController::class, 'createProvider'])->name('.fees.provider.create');
        Route::post('/fees/provider', [AdminFeeController::class, 'storeProvider'])->name('.fees.provider.store');
        Route::get('/fees/provider/{providerFeeTier}/edit', [AdminFeeController::class, 'editProvider'])->name('.fees.provider.edit');
        Route::put('/fees/provider/{providerFeeTier}', [AdminFeeController::class, 'updateProvider'])->name('.fees.provider.update');
        Route::delete('/fees/provider/{providerFeeTier}', [AdminFeeController::class, 'destroyProvider'])->name('.fees.provider.destroy');
        Route::get('/fees/agent', [AdminFeeController::class, 'agent'])->name('.fees.agent');
        Route::get('/fees/agent/create', [AdminFeeController::class, 'createAgent'])->name('.fees.agent.create');
        Route::post('/fees/agent', [AdminFeeController::class, 'storeAgent'])->name('.fees.agent.store');
        Route::get('/fees/agent/{agentCommissionTier}', [AdminFeeController::class, 'showAgent'])->name('.fees.agent.show');
        Route::get('/fees/agent/{agentCommissionTier}/edit', [AdminFeeController::class, 'editAgent'])->name('.fees.agent.edit');
        Route::put('/fees/agent/{agentCommissionTier}', [AdminFeeController::class, 'updateAgent'])->name('.fees.agent.update');
        Route::delete('/fees/agent/{agentCommissionTier}', [AdminFeeController::class, 'destroyAgent'])->name('.fees.agent.destroy');
        Route::get('/fees/transfer', [AdminFeeController::class, 'transfer'])->name('.fees.transfer');
        Route::get('/fees/transfer/create', [AdminFeeController::class, 'createTransfer'])->name('.fees.transfer.create');
        Route::post('/fees/transfer', [AdminFeeController::class, 'storeTransfer'])->name('.fees.transfer.store');
        Route::get('/fees/transfer/{transferFeeTier}/edit', [AdminFeeController::class, 'editTransfer'])->name('.fees.transfer.edit');
        Route::put('/fees/transfer/{transferFeeTier}', [AdminFeeController::class, 'updateTransfer'])->name('.fees.transfer.update');
        Route::delete('/fees/transfer/{transferFeeTier}', [AdminFeeController::class, 'destroyTransfer'])->name('.fees.transfer.destroy');
        Route::get('/fees/{providerFeeTier}', [AdminFeeController::class, 'showProvider'])->name('.fees.show');
        Route::get('/fees/{providerFeeTier}/edit', [AdminFeeController::class, 'editProvider'])->name('.fees.edit');
        Route::get('/transactions', fn (Request $request) => app(AdminReadController::class)->transactions($request))->name('.transactions');
        Route::get('/transactions/cash-in', fn (Request $request) => app(AdminReadController::class)->transactions($request, 'cash_in'))->name('.transactions.cash-in');
        Route::get('/transactions/cash-out', fn (Request $request) => app(AdminReadController::class)->transactions($request, 'cash_out'))->name('.transactions.cash-out');
        Route::get('/transactions/transfer', fn (Request $request) => app(AdminReadController::class)->transactions($request, 'transfer'))->name('.transactions.transfer');
        Route::get('/transactions/exchange', fn (Request $request) => app(AdminReadController::class)->transactions($request, 'exchange'))->name('.transactions.exchange');
        Route::get('/{section}', fn (Request $request, string $section) => $renderAdminOperations($request, $section))
            ->whereIn('section', array_keys($adminSections))
            ->name('.section');
        Route::get('/{section}/create', fn (Request $request, string $section) => $renderAdminOperations($request, $section, 'create'))
            ->whereIn('section', $adminCrudSections)
            ->name('.create');
        Route::get('/transactions/activity-logs', fn (Request $request) => $renderAdminOperations($request, 'transactions', 'list', null, 'activity-logs'))
            ->name('.transactions.activity-logs');
        Route::get('/vault/log', [AdminReadController::class, 'vaultLog'])->name('.vault.log');
        Route::get('/reports/reconciliations', [AdminReadController::class, 'reconciliations'])->name('.reports.reconciliations');
        Route::get('/{section}/{resourceId}/edit', fn (Request $request, string $section, int $resourceId) => $renderAdminOperations($request, $section, 'edit', $resourceId))
            ->whereIn('section', $adminCrudSections)
            ->whereNumber('resourceId')
            ->name('.edit');
        Route::get('/{section}/{resourceId}', fn (Request $request, string $section, int $resourceId) => $renderAdminOperations($request, $section, 'detail', $resourceId))
            ->whereIn('section', $adminDetailSections)
            ->whereNumber('resourceId')
            ->name('.detail');
    });
Route::middleware(['auth', 'role:cashier'])->get('/cashier', CashierController::class)->name('cashier');
Route::middleware(['auth', 'role:cashier'])
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function (): void {
        Route::post('/transactions/{transaction}/confirm-cash-in', [CashierActionController::class, 'confirmCashIn'])->name('transactions.confirm-cash-in');
        Route::post('/transactions/{transaction}/cancel-cash-in', [CashierActionController::class, 'cancelCashIn'])->name('transactions.cancel-cash-in');
    });
Route::middleware(['auth', 'role:cashier'])
    ->prefix('cashier')
    ->name('cashier.')
    ->group(function (): void {
        Route::get('/profile', [CashierController::class, 'profile'])->name('profile');
        Route::post('/profile/password', [CashierActionController::class, 'updatePassword'])->name('profile.password');
        Route::post('/profile/pin', [CashierActionController::class, 'updatePin'])->name('profile.pin');
        Route::post('/cash-floats', [CashierActionController::class, 'issueFloat'])->name('cash-floats.store');
        Route::post('/cash-floats/{float}/confirm-return', [CashierActionController::class, 'confirmFloatReturn'])->name('cash-floats.confirm-return');
        Route::post('/notifications/{transaction}/read', [CashierActionController::class, 'markNotificationRead'])->name('notifications.read');
        Route::post('/transactions/{transaction}/confirm-cash-in', [CashierActionController::class, 'confirmCashIn'])->name('transactions.confirm-cash-in');
        Route::post('/transactions/{transaction}/cancel-cash-in', [CashierActionController::class, 'cancelCashIn'])->name('transactions.cancel-cash-in');
        Route::get('/{section}', CashierController::class)
            ->whereIn('section', [
                'dashboard',
                'teller-entry-notifications',
                'main-vault-denomination-stock',
                'morning-issue',
                'end-of-day',
                'teller-entry-history',
                'teller-entry-history-cash-in',
                'teller-entry-history-cash-out',
                'teller-entry-history-transfer',
                'teller-entry-history-exchange',
                'main-vault-audit-log',
            ])
            ->name('section');
    });
Route::middleware(['auth', 'role:teller'])
    ->prefix('transactions')
    ->name('transactions.')
    ->controller(TransactionEntryController::class)
    ->group(function (): void {
        Route::get('/cash-in', 'cashIn')->name('cash-in');
        Route::get('/cash-in/history', 'cashInHistory')->name('cash-in.history');
        Route::post('/cash-in', 'cashInStore')->name('cash-in.store');
    });

Route::middleware(['auth', 'role:teller'])
    ->prefix('transactions')
    ->name('transactions.')
    ->controller(TransactionEntryController::class)
    ->group(function (): void {
        Route::redirect('/', '/transactions/transfer')->name('index');
        Route::get('/cash-out', 'cashOut')->name('cash-out');
        Route::get('/cash-out/history', 'cashOutHistory')->name('cash-out.history');
        Route::get('/transfer', 'transfer')->name('transfer');
        Route::get('/transfer/history', 'transferHistory')->name('transfer.history');
        Route::get('/exchange', 'exchange')->name('exchange');
        Route::get('/exchange/history', 'exchangeHistory')->name('exchange.history');
    });

Route::middleware(['auth', 'role:teller'])
    ->prefix('transactions')
    ->name('transactions.')
    ->controller(TransactionEntryController::class)
    ->group(function (): void {
        Route::post('/cash-out', 'cashOutStore')->name('cash-out.store');
        Route::post('/transfer', 'transferStore')->name('transfer.store');
        Route::post('/exchange', 'exchangeStore')->name('exchange.store');
    });

Route::middleware(['auth', 'role:teller'])
    ->prefix('teller')
    ->name('teller.')
    ->controller(TellerController::class)
    ->group(function (): void {
        Route::get('/', 'counter')->name('counter');
        Route::redirect('/cash-in', '/transactions/cash-in')->name('cash-in');
        Route::redirect('/cash-out', '/transactions/cash-out')->name('cash-out');
        Route::redirect('/transfer', '/transactions/transfer')->name('transfer');
        Route::redirect('/exchange', '/transactions/exchange')->name('exchange');
        Route::get('/float', 'floatPage')->name('float');
        Route::get('/float/history', 'floatHistory')->name('float.history');
    });

Route::middleware(['auth', 'role:teller'])
    ->prefix('teller/floats')
    ->name('teller.floats.')
    ->controller(TellerFloatActionController::class)
    ->group(function (): void {
        Route::post('/issues/{issue}/receive', 'receiveIssue')->name('issues.receive');
        Route::post('/issues/{issue}/reject', 'rejectIssue')->name('issues.reject');
        Route::post('/{float}/activate', 'activate')->name('activate');
        Route::post('/{float}/reject', 'reject')->name('reject');
        Route::post('/{float}/initiate-return', 'initiateReturn')->name('initiate-return');
    });

if (! function_exists('minimal_pdf')) {
    function minimal_pdf(array $lines): string
    {
        $escape = fn (string $value): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
        $content = "BT\n/F1 16 Tf\n50 790 Td\n";
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -24 Td\n";
            }
            $content .= '('.$escape($line).") Tj\n";
        }
        $content .= "ET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length ".strlen($content)." >>\nstream\n{$content}endstream\nendobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
