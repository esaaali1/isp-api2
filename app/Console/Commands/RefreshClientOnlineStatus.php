<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Polls every agent's MikroTik router for each client's live connection
 * status and caches the online client IDs per agent, so API requests can
 * read a ready-made list instead of querying MikroTik on every request.
 * Scheduled to run every minute (see routes/console.php); cache TTL is a
 * bit longer than the schedule interval so a slow/missed run doesn't make
 * the count briefly disappear.
 */
class RefreshClientOnlineStatus extends Command
{
    protected $signature = 'clients:refresh-online-status';

    protected $description = "Refresh the cached list of each agent's currently-online clients from MikroTik";

    public function handle(MikrotikService $mikrotik): int
    {
        Agent::with('clients')->chunkById(50, function ($agents) use ($mikrotik) {
            foreach ($agents as $agent) {
                // اتصال واحد بالراوتر يُستخدم لكل عملاء هذا الوكيل دفعة
                // واحدة، بدل اتصال منفصل لكل عميل (كان هذا سبب البطء).
                $onlineUsernames = $mikrotik->onlineUsernamesForAgent($agent);

                $onlineIds = $agent->clients
                    ->whereIn('username', $onlineUsernames)
                    ->pluck('id')
                    ->all();

                Cache::put("agent_online_clients:{$agent->id}", $onlineIds, now()->addMinutes(2));
            }
        });

        return self::SUCCESS;
    }
}
