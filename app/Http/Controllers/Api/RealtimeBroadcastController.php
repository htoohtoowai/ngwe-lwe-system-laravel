<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RealtimeBroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeBroadcastController extends Controller
{
    public function __construct(private readonly RealtimeBroadcastService $broadcasts) {}

    public function test(Request $request): JsonResponse
    {
        $this->broadcasts->ping($request->user());

        return response()->json([
            'message' => 'Broadcast ping dispatched',
        ]);
    }
}
