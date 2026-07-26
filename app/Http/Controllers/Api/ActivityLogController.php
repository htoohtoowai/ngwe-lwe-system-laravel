<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only.');

        $logs = ActivityLog::query()
            ->with('user:id,username,full_name')
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', $request->string('entity_type')->trim()->value()))
            ->when($request->filled('action'), fn ($query) => $query->where('action', 'like', '%'.$request->string('action')->trim()->value().'%'))
            ->when($request->filled('date'), fn ($query) => $query->whereDate('created_at', $request->date('date')))
            ->latest('created_at')
            ->paginate(min(max($request->integer('per_page', 200), 1), 1000));

        return ActivityLogResource::collection($logs);
    }
}
