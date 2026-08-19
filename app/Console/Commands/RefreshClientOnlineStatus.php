<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Every minute (see routes/console.php), connects once to each agent's
 * MikroTik router to do two things with that single connection:
 *
 * 1. Disconnect any active PPPoE/Hotspot session belonging to a client
 *    whose subscription has already expired — otherwise a client stays
 *    connected indefinitely past expiry, since RADIUS only rejects new
 *    login attempts (the "Expiration" check in radcheck), it does not
 *    tear down sessions that are already up.
 * 2. Cache the (now-accurate) list of online client IDs per agent, so
 *    API requests read a ready-made list instead of querying MikroTik
 *    on every request. Cache TTL is a bit longer than the schedule
 *    interval so a slow/missed run doesn't make the count briefly
 *    disappear.
 */
class RefreshClientOnlineStatus extends Command
{
    protected $signature = 'clients:refresh-online-status';

    protected $description = "Disconnect expired clients and refresh the cached list of each agent's currently-online clients from MikroTik";

    public function handle(MikrotikService $mikrotik): int
    {
        Agent::with('clients')->chunkById(50, function ($agents) use ($mikrotik) {
            foreach ($agents as $agent) {
                $expiredUsernames = $agent->clients
                    ->where('end_date', '<', now())
                    ->pluck('username')
                    ->all();

                // اتصال واحد بالراوتر يُستخدم لكل عملاء هذا الوكيل دفعة
                // واحدة: يفصل جلسات المنتهين فوراً ويعيد قائمة المتصلين
                // الفعليين بعد ذلك — بدل اتصال منفصل لكل عميل.
                $onlineUsernames = $mikrotik->refreshAgentSessions($agent, $expiredUsernames);

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
