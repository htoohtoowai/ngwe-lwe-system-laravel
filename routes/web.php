<?php

use App\Http\Controllers\Api\SystemCompatibilityController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\TellerController;
use App\Http\Controllers\TransactionEntryController;
use App\Models\Transaction;
use App\Services\DailyReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health', fn () => response()->json(['status' => 'ok']))->name('health');
Route::middleware('ngwe.auth')->post('/ws-ticket', [SystemCompatibilityController::class, 'wsTicket'])->name('ws-ticket');
Route::get('/login', LoginController::class)->name('login');
Route::inertia('/', 'RootRedirect')->name('home');
Route::middleware(['ngwe.auth', 'role:admin'])->get('/reports/daily/pdf', function (Request $request, DailyReportService $reports) {
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
Route::middleware('ngwe.auth')->get('/dashboard', DashboardController::class)->name('dashboard');
$adminSections = [
    'overview' => 'overview',
    'companies' => 'companies',
    'service-types' => 'service-types',
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
    'service-types',
    'exchange-rates',
    'accounts',
    'fees',
    'users',
];
$adminDetailSections = [
    ...$adminCrudSections,
    'transactions',
];
$renderAdminOperations = static function (
    Request $request,
    string $section = 'overview',
    string $mode = 'list',
    ?int $resourceId = null,
    ?string $transactionSubsection = null,
) use ($adminSections) {
    abort_unless(array_key_exists($section, $adminSections), 404);

    return Inertia::render('admin/Operations', [
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
    ]);
};
Route::middleware(['ngwe.auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.operations')
    ->group(function () use ($adminSections, $adminCrudSections, $adminDetailSections, $renderAdminOperations): void {
        Route::get('/', fn (Request $request) => $renderAdminOperations($request))
            ->name('');
        Route::get('/overview', fn (Request $request) => $renderAdminOperations($request))
            ->name('.overview');
        Route::get('/{section}', fn (Request $request, string $section) => $renderAdminOperations($request, $section))
            ->whereIn('section', array_keys($adminSections))
            ->name('.section');
        Route::get('/{section}/create', fn (Request $request, string $section) => $renderAdminOperations($request, $section, 'create'))
            ->whereIn('section', $adminCrudSections)
            ->name('.create');
        Route::get('/transactions/activity-logs', fn (Request $request) => $renderAdminOperations($request, 'transactions', 'list', null, 'activity-logs'))
            ->name('.transactions.activity-logs');
        Route::get('/{section}/{resourceId}/edit', fn (Request $request, string $section, int $resourceId) => $renderAdminOperations($request, $section, 'edit', $resourceId))
            ->whereIn('section', $adminCrudSections)
            ->whereNumber('resourceId')
            ->name('.edit');
        Route::get('/{section}/{resourceId}', fn (Request $request, string $section, int $resourceId) => $renderAdminOperations($request, $section, 'detail', $resourceId))
            ->whereIn('section', $adminDetailSections)
            ->whereNumber('resourceId')
            ->name('.detail');
    });
Route::middleware(['ngwe.auth', 'role:cashier'])->get('/cashier', CashierController::class)->name('cashier');
Route::middleware(['ngwe.auth', 'role:cashier'])
    ->prefix('cashier')
    ->name('cashier.')
    ->group(function (): void {
        Route::get('/profile', [CashierController::class, 'profile'])->name('profile');
        Route::get('/{section}', CashierController::class)
            ->whereIn('section', [
                'teller-entry-notifications',
                'main-vault-denomination-stock',
                'morning-issue',
                'end-of-day',
                'teller-entry-history',
                'main-vault-audit-log',
            ])
            ->name('section');
    });
Route::middleware(['ngwe.auth', 'role:teller'])
    ->prefix('transactions')
    ->name('transactions.')
    ->controller(TransactionEntryController::class)
    ->group(function (): void {
        Route::get('/cash-in', 'cashIn')->name('cash-in');
        Route::post('/cash-in', 'cashInStore')->name('cash-in.store');
    });

Route::middleware(['ngwe.auth', 'role:teller'])
    ->prefix('transactions')
    ->name('transactions.')
    ->controller(TransactionEntryController::class)
    ->group(function (): void {
        Route::redirect('/', '/transactions/transfer')->name('index');
        Route::get('/cash-out', 'cashOut')->name('cash-out');
        Route::get('/transfer', 'transfer')->name('transfer');
        Route::get('/exchange', 'exchange')->name('exchange');
    });

Route::middleware(['ngwe.auth', 'role:teller'])
    ->prefix('transactions')
    ->name('transactions.')
    ->controller(TransactionEntryController::class)
    ->group(function (): void {
        Route::post('/cash-out', 'cashOutStore')->name('cash-out.store');
        Route::post('/transfer', 'transferStore')->name('transfer.store');
        Route::post('/exchange', 'exchangeStore')->name('exchange.store');
    });

Route::middleware(['ngwe.auth', 'role:teller'])
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
        Route::post('/transactions/cash-out', 'cashOutStore')->name('transactions.cash-out');
        Route::post('/transactions/transfer', 'transferStore')->name('transactions.transfer');
        Route::post('/transactions/exchange', 'exchangeStore')->name('transactions.exchange');
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
