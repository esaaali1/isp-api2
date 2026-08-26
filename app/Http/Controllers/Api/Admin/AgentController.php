<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminAgentResource;
use App\Models\Agent;
use App\Models\AgentLog;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** لوحة الإدارة: إدارة كل حسابات الوكلاء (غير حسابات الإدارة نفسها). محمي بـ middleware('admin'). */
class AgentController extends Controller
{
    public function __construct(private readonly MikrotikService $mikrotik)
    {
    }

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

    /** الوكلاء المتصلون الآن — عبر محاولة اتصال حية بجهاز المايكروتك الخاص بكل وكيل. */
    public function online(): JsonResponse
    {
        $agents = Agent::where('is_admin', false)->withCount('clients')->get();

        $online = $agents->filter(fn (Agent $agent) => $this->mikrotik->pingAgent($agent))->values();

        return response()->json(AdminAgentResource::collection($online));
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
}
