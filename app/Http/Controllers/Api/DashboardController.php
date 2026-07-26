<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\DailyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DashboardController extends Controller
{
    public function __construct(private readonly DailyReportService $reports) {}

    public function summary(Request $request): JsonResponse
    {
        $this->guardAdmin($request);

        return response()->json($this->reports->summary(now()->toDateString()));
    }

    public function accounts(Request $request): AnonymousResourceCollection
    {
        $this->guardAdmin($request);

        return AccountResource::collection(
            Account::query()->with('serviceType.company')->where('is_active', true)->orderBy('account_name')->get(),
        );
    }

    private function guardAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only.');
    }
}
