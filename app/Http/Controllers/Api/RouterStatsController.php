<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouterStatsController extends Controller
{
    public function __construct(private readonly MikrotikService $mikrotik)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        return response()->json($this->mikrotik->routerStatistics($agent));
    }
}
