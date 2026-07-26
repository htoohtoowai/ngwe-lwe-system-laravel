<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DailyReportRequest;
use App\Http\Resources\DailyReconciliationResource;
use App\Services\DailyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly DailyReportService $reports) {}

    public function dailySummary(DailyReportRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->reports->summary($data['date'] ?? now()->toDateString()),
        ]);
    }

    public function daily(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only.');
        $date = $request->validate(['date' => ['required', 'date']])['date'];

        return response()->json($this->reports->summary($date));
    }

    public function current(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only.');

        return response()->json($this->reports->summary(now()->toDateString()));
    }

    public function closeDay(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only.');
        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $log = $this->reports->close(
            $request->user(),
            $data['date'] ?? now()->toDateString(),
            $data['notes'] ?? null,
        );

        return response()->json([
            'message' => 'Day closed successfully.',
            'reconciliation_id' => $log->id,
            'snapshot' => $this->reports->summary($log->recon_date->toDateString()),
        ], 201);
    }

    public function history(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only.');

        return DailyReconciliationResource::collection(
            $this->reports->reconciliations(perPage: min(max($request->integer('limit', 30), 1), 100)),
        );
    }

    public function closeDailyReconciliation(DailyReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $log = $this->reports->close(
            $request->user(),
            $data['date'] ?? now()->toDateString(),
            $data['notes'] ?? null,
        );

        return (new DailyReconciliationResource($log))
            ->response()
            ->setStatusCode(201);
    }

    public function dailyReconciliations(DailyReportRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        return DailyReconciliationResource::collection(
            $this->reports->reconciliations(
                dateFrom: $data['date_from'] ?? null,
                dateTo: $data['date_to'] ?? null,
                perPage: (int) ($data['per_page'] ?? 30),
            ),
        );
    }
}
