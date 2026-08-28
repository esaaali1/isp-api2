<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * كل دقيقة (انظر routes/console.php): يفحص جهاز المايكروتك لكل وكيل
 * (غير وكلاء الإدارة) ويخزّن نتيجة "متصل أم لا" في cache — لتقرأها
 * لوحة الإدارة فوراً بدل فحص كل وكيل حياً عند كل طلب (كان يستغرق حتى
 * 45 ثانية مع عدة وكلاء). نفس نمط clients:refresh-online-status تماماً.
 */
class RefreshAgentOnlineStatus extends Command
{
    protected $signature = 'agents:refresh-online-status';

    protected $description = "Refresh the cached online/offline status of each agent's MikroTik router";

    public function handle(MikrotikService $mikrotik): int
    {
        $onlineIds = [];

        Agent::where('is_admin', false)->chunkById(50, function ($agents) use ($mikrotik, &$onlineIds) {
            foreach ($agents as $agent) {
                if ($mikrotik->pingAgent($agent)) {
                    $onlineIds[] = $agent->id;
                }
            }
        });

        Cache::put('admin_online_agents', $onlineIds, now()->addMinutes(2));

        return self::SUCCESS;
    }
}
