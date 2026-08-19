<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\ClientLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        $now = now();
        $soon = now()->addDays(7);

        $clients = $agent->clients();

        $totalClients = (clone $clients)->count();
        $activeClients = (clone $clients)->where('start_date', '<=', $now)->where('end_date', '>=', $now)->count();
        $expiredClients = (clone $clients)->where('end_date', '<', $now)->count();
        $expiringSoon = (clone $clients)->where('end_date', '>=', $now)->where('end_date', '<=', $soon)->count();

        $recentLogs = ClientLog::whereIn('client_id', $agent->clients()->pluck('id'))
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'client_id', 'action', 'old_value', 'new_value', 'created_at']);

        return response()->json([
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'subscription_active' => $agent->isSubscriptionActive(),
                'end_date' => $agent->end_date?->toDateString(),
            ],
            'stats' => [
                'total_clients' => $totalClients,
                'active_clients' => $activeClients,
                'expired_clients' => $expiredClients,
                'expiring_soon' => $expiringSoon,
            ],
            'recent_logs' => $recentLogs,
        ]);
    }
}
