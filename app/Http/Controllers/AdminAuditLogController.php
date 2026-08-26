<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuditLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $query = $this->filteredQuery($request)->with('user')->latest('id');
        $paginator = $query->paginate(50)->withQueryString();

        return Inertia::render('admin/AuditLogs', [
            'role' => 'admin',
            'announcement' => 'Immutable system activity audit trail.',
            'notificationCount' => Transaction::query()
                ->whereIn('transaction_type', ['cash_in', 'send_money'])
                ->where('status', 'PENDING_CASHIER_CONFIRM')
                ->count(),
            'rows' => ActivityLogResource::collection($paginator->items())->resolve($request),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'user_id' => $request->query('user_id'),
                'role' => (string) $request->query('role', ''),
                'category' => (string) $request->query('category', ''),
                'action' => (string) $request->query('action', ''),
                'module' => (string) $request->query('module', ''),
                'status' => (string) $request->query('status', ''),
                'date_from' => (string) $request->query('date_from', ''),
                'date_to' => (string) $request->query('date_to', ''),
            ],
            'users' => User::query()->orderBy('full_name')->get(['id', 'full_name', 'username', 'role']),
            'filterOptions' => [
                'categories' => ActivityLog::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->values(),
                'actions' => ActivityLog::query()->whereNotNull('action')->distinct()->orderBy('action')->pluck('action')->values(),
                'modules' => ActivityLog::query()->whereNotNull('module')->distinct()->orderBy('module')->pluck('module')->values(),
                'roles' => ActivityLog::query()->whereNotNull('actor_role')->distinct()->orderBy('actor_role')->pluck('actor_role')->values(),
                'statuses' => ActivityLog::query()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status')->values(),
            ],
        ]);
    }

    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $audit->record(
            action: 'audit_export',
            category: 'system',
            module: 'audit_logs',
            entityType: 'activity_log',
            description: 'Exported system activity audit log',
            details: ['filters' => $request->except(['page'])],
        );

        $filename = 'system-audit-'.now()->format('Ymd-His').'.csv';
        $query = $this->filteredQuery($request)->with('user')->latest('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, [
                'Time', 'User', 'Role', 'Category', 'Action', 'Module', 'Result',
                'Entity', 'Entity ID', 'Description', 'IP Address', 'Route',
                'HTTP Method', 'Request ID', 'Failure Reason', 'Changed Fields',
                'Before', 'After', 'Details',
            ]);

            $query->chunkByIdDesc(500, function ($rows) use ($out): void {
                foreach ($rows as $log) {
                    fputcsv($out, [
                        $log->created_at?->toIso8601String(),
                        $log->actor_name ?? $log->user?->full_name ?? $log->user?->username,
                        $log->actor_role ?? $log->user?->role,
                        $log->category,
                        $log->action,
                        $log->module,
                        $log->status,
                        $log->entity_type,
                        $log->entity_id,
                        $log->description,
                        $log->ip_address,
                        $log->route,
                        $log->http_method,
                        $log->request_id,
                        $log->failure_reason,
                        json_encode($log->changed_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($log->old_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($log->new_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($log->details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredQuery(Request $request): Builder
    {
        return ActivityLog::query()
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', (int) $request->query('user_id')))
            ->when($request->filled('role'), fn (Builder $q) => $q->where('actor_role', (string) $request->query('role')))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category', (string) $request->query('category')))
            ->when($request->filled('action'), fn (Builder $q) => $q->where('action', (string) $request->query('action')))
            ->when($request->filled('module'), fn (Builder $q) => $q->where('module', (string) $request->query('module')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', (string) $request->query('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', (string) $request->query('date_to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $search = '%'.trim((string) $request->query('search')).'%';
                $q->where(function (Builder $nested) use ($search): void {
                    $nested->where('actor_name', 'like', $search)
                        ->orWhere('action', 'like', $search)
                        ->orWhere('module', 'like', $search)
                        ->orWhere('entity_type', 'like', $search)
                        ->orWhere('description', 'like', $search)
                        ->orWhere('request_id', 'like', $search)
                        ->orWhere('ip_address', 'like', $search);
                });
            });
    }
}
