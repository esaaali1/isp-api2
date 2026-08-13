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

        $today = now()->toDateString();
        $soon = now()->addDays(7)->toDateString();

        $clients = $agent->clients();

        $totalClients = (clone $clients)->count();
        $activeClients = (clone $clients)->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->count();
        $expiredClients = (clone $clients)->whereDate('end_date', '<', $today)->count();
        $expiringSoon = (clone $clients)->whereDate('end_date', '>=', $today)->whereDate('end_date', '<=', $soon)->count();

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
