<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DailyReportRequest;
use App\Http\Resources\DailyReconciliationResource;
use App\Services\DailyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
