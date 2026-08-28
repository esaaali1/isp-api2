<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminAgentResource;
use App\Models\Agent;
use App\Models\AgentLog;
use App\Services\RadiusProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\Process\Process;

/** لوحة الإدارة: إدارة كل حسابات الوكلاء (غير حسابات الإدارة نفسها). محمي بـ middleware('admin'). */
class AgentController extends Controller
{
    /** كل الوكلاء (أو المنتهية اشتراكاتهم فقط عبر status=expired)، مع عدد مشتركي كل وكيل. */
    public function index(Request $request): JsonResponse
    {
        $query = Agent::where('is_admin', false)->withCount('clients');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($request->string('status')->value() === 'expired') {
            $query->where('end_date', '<', now()->toDateString());
        }

        $agents = $query->orderBy('name')->paginate($request->integer('per_page', 100));

        return response()->json(AdminAgentResource::collection($agents)->response()->getData(true));
    }

    /**
     * الوكلاء المتصلون الآن — من cache يُحدَّث كل دقيقة عبر أمر
     * agents:refresh-online-status (بدل فحص كل وكيل حياً عند كل طلب،
     * وهو ما كان يستغرق حتى نصف دقيقة مع عدة وكلاء).
     */
    public function online(): JsonResponse
    {
        $onlineIds = Cache::get('admin_online_agents', []);

        $agents = Agent::where('is_admin', false)
            ->whereIn('id', $onlineIds)
            ->withCount('clients')
            ->get();

        return response()->json(AdminAgentResource::collection($agents));
    }

    /** سجل التغييرات على كل الوكلاء مجتمعين (الأحدث أولاً)، مع اسم الوكيل صاحب كل تغيير. */
    public function logs(): JsonResponse
    {
        $logs = AgentLog::with('agent:id,name,username')
            ->whereIn('agent_id', Agent::where('is_admin', false)->pluck('id'))
            ->latest('created_at')
            ->get(['id', 'agent_id', 'action', 'old_value', 'new_value', 'created_at']);

        return response()->json($logs);
    }

    public function show(Agent $agent): JsonResponse
    {
        abort_if($agent->is_admin, 404);

        return response()->json(new AdminAgentResource($agent->loadCount('clients')));
    }

    /**
     * ينشئ وكيلاً جديداً: يولّد زوج مفاتيح WireGuard، يخصّص له أول عنوان
     * 10.0.0.X متاح (بعد آخر عنوان مستخدم فعلياً، بلا إعادة استخدام
     * فراغات)، يضيفه كـ Peer حي في wg0.conf وكعميل في FreeRADIUS عبر
     * سكربت مقيَّد بصلاحية sudo محدودة (انظر isp-provision-agent.sh —
     * لا يلمس أي وكيل موجود، ولا يوقف أي خدمة بالكامل)، ولا يُنشئ صف
     * الوكيل في قاعدة البيانات إلا بعد نجاح هذا التزويد فعلياً.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:agents,username'],
            'password' => ['required', 'string', 'min:4'],
            'mikrotik_user' => ['required', 'string', 'max:255'],
            'mikrotik_pass' => ['required', 'string', 'max:255'],
            'mikrotik_port' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        $genkey = new Process(['wg', 'genkey']);
        $genkey->mustRun();
        $privateKey = trim($genkey->getOutput());

        $pubkeyProcess = new Process(['wg', 'pubkey']);
        $pubkeyProcess->setInput($privateKey);
        $pubkeyProcess->mustRun();
        $publicKey = trim($pubkeyProcess->getOutput());

        $octet = $this->nextWireguardOctet();
        if ($octet > 254) {
            return response()->json(['message' => 'نطاق عناوين WireGuard ممتلئ (10.0.0.0/24).'], 500);
        }

        $wgIp = "10.0.0.{$octet}";
        $slug = "mikrotik_{$octet}";

        $provision = new Process(['sudo', '/usr/local/sbin/isp-provision-agent.sh', (string) $octet, $publicKey, $slug]);
        $provision->setTimeout(20);
        $provision->run();

        if (! $provision->isSuccessful()) {
            $error = trim($provision->getErrorOutput() ?: $provision->getOutput()) ?: 'خطأ غير معروف.';

            return response()->json(['message' => "فشل تجهيز اتصال WireGuard/RADIUS: {$error}"], 500);
        }

        $agent = Agent::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'password' => $validated['password'],
            'is_admin' => false,
            'mikrotik_host' => $wgIp,
            'mikrotik_user' => $validated['mikrotik_user'],
            'mikrotik_pass' => $validated['mikrotik_pass'],
            'mikrotik_port' => $validated['mikrotik_port'],
            'wireguard_private_key' => $privateKey,
            'wireguard_public_key' => $publicKey,
            'balance' => 0,
            'electronic_payment_enabled' => false,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);

        AgentLog::create([
            'agent_id' => $agent->id,
            'action' => 'created',
            'old_value' => null,
            'new_value' => $agent->username,
        ]);

        return response()->json(new AdminAgentResource($agent->loadCount('clients')), 201);
    }

    /**
     * أول عنوان 10.0.0.X متاح بعد آخر عنوان مستخدم فعلياً — يفحص كلاً من
     * wg0.conf الحي وجدول agents معاً (احتياطاً من أي تعارض بينهما).
     */
    private function nextWireguardOctet(): int
    {
        $used = [1]; // 10.0.0.1 هو عنوان الواجهة نفسها

        $wgConf = @file_get_contents('/etc/wireguard/wg0.conf') ?: '';
        preg_match_all('/AllowedIPs\s*=\s*10\.0\.0\.(\d+)\/32/', $wgConf, $matches);
        foreach ($matches[1] as $octet) {
            $used[] = (int) $octet;
        }

        foreach (Agent::whereNotNull('mikrotik_host')->pluck('mikrotik_host') as $host) {
            if (preg_match('/^10\.0\.0\.(\d+)$/', (string) $host, $m)) {
                $used[] = (int) $m[1];
            }
        }

        return max($used) + 1;
    }

    public function update(Request $request, Agent $agent): JsonResponse
    {
        abort_if($agent->is_admin, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'max:255', Rule::unique('agents', 'username')->ignore($agent->id)],
            'password' => ['sometimes', 'string', 'min:4'],
            'mikrotik_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mikrotik_user' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mikrotik_pass' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mikrotik_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
        ]);

        $changes = [];
        foreach ($validated as $field => $value) {
            if ((string) $agent->{$field} !== (string) $value) {
                $changes[] = [
                    'agent_id' => $agent->id,
                    'action' => "{$field}_change",
                    'old_value' => (string) $agent->{$field},
                    'new_value' => (string) $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        $agent->update($validated);

        if ($changes !== []) {
            AgentLog::insert($changes);
        }

        return response()->json(new AdminAgentResource($agent->fresh()->loadCount('clients')));
    }

    /** سجل تغييرات وكيل واحد فقط (الأحدث أولاً). */
    public function agentLogs(Agent $agent): JsonResponse
    {
        abort_if($agent->is_admin, 404);

        $logs = $agent->logs()->latest('created_at')
            ->get(['id', 'agent_id', 'action', 'old_value', 'new_value', 'created_at']);

        return response()->json($logs);
    }

    /** يضيف 30 يوماً إلى تاريخ انتهاء اشتراك الوكيل نفسه (وليس اشتراكات مشتركيه). */
    public function renew(Agent $agent): JsonResponse
    {
        abort_if($agent->is_admin, 404);

        $oldEndDate = $agent->end_date->toDateString();
        $newEndDate = $agent->end_date->copy()->addDays(30)->toDateString();

        $agent->update(['end_date' => $newEndDate]);

        AgentLog::create([
            'agent_id' => $agent->id,
            'action' => 'renew',
            'old_value' => $oldEndDate,
            'new_value' => $newEndDate,
        ]);

        return response()->json(new AdminAgentResource($agent->fresh()->loadCount('clients')));
    }

    /**
     * يحذف الوكيل نهائياً من قاعدة البيانات مع كل بياناته: مشتركيه،
     * أسعار باقاته، وسجل تغييراته (تُحذف تلقائياً عبر cascade على
     * agent_id)، ورموز دخوله. الأهم: يزيل أولاً بيانات دخول كل مشترك من
     * جداول RADIUS (radcheck/radreply/radusergroup) — هذه الجداول لا
     * ترتبط بقيد خارجي بجدول clients، فحذف صفوف المشتركين وحده كان
     * سيُبقي حسابات PPPoE فعّالة قادرة على الاتصال رغم اختفائها من
     * النظام. كل شيء داخل معاملة واحدة لتفادي حذف جزئي عند أي خطأ.
     *
     * بعد نجاح حذف قاعدة البيانات، يزيل أيضاً اتصال WireGuard وعميل
     * RADIUS الخاصين بالوكيل نفسه (وليس مشتركيه) — فقط إن كان مزوَّداً
     * عبر هذا النظام أصلاً (له مفتاح WireGuard مخزَّن)؛ الوكلاء القدامى
     * المُعدّون يدوياً قبل هذه الميزة تُترك اتصالاتهم كما هي دون أي مساس.
     */
    public function destroy(Agent $agent, RadiusProvisioningService $radius): JsonResponse
    {
        abort_if($agent->is_admin, 404);

        $wgPublicKey = $agent->wireguard_public_key;
        $octet = null;
        if ($wgPublicKey && preg_match('/^10\.0\.0\.(\d+)$/', (string) $agent->mikrotik_host, $m)) {
            $octet = (int) $m[1];
        }

        DB::transaction(function () use ($agent, $radius) {
            foreach ($agent->clients()->pluck('username') as $username) {
                $radius->deprovision($username);
            }

            $agent->tokens()->delete();
            $agent->delete();
        });

        if ($octet !== null) {
            $deprovision = new Process([
                'sudo', '/usr/local/sbin/isp-deprovision-agent.sh',
                (string) $octet, $wgPublicKey, "mikrotik_{$octet}",
            ]);
            $deprovision->setTimeout(20);
            $deprovision->run();
            // لا نفشل الطلب لو تعذّرت إزالة الشبكة بعد نجاح حذف الوكيل
            // من قاعدة البيانات فعلاً — الوكيل صار غير موجود على أي حال.
        }

        return response()->json(status: 204);
    }
}
