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

    /** التدفّق اللحظي لمنافذ محدّدة، تُمرَّر كقائمة مفصولة بفواصل مثل "ether3,ether4". */
    public function traffic(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        $validated = $request->validate(['ports' => ['required', 'string']]);

        $names = array_values(array_filter(
            array_map('trim', explode(',', $validated['ports'])),
            fn (string $name) => (bool) preg_match('/^[a-zA-Z0-9]{1,32}$/', $name),
        ));

        return response()->json(['ports' => $this->mikrotik->portsTraffic($agent, $names)]);
    }
}
