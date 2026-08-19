<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MikrotikConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Agent;
use App\Models\Client;
use App\Models\ClientLog;
use App\Services\MikrotikService;
use App\Services\RadiusProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        /** @var Agent $agent */
        $agent = $request->user();

        $query = $agent->clients();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('fullname', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($request->string('status')->value() === 'active') {
            $today = now()->toDateString();
            $query->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today);
        } elseif ($request->string('status')->value() === 'expired') {
            $query->whereDate('end_date', '<', now()->toDateString());
        }

        $clients = $query->orderBy('fullname')->paginate($request->integer('per_page', 25));

        return response()->json(ClientResource::collection($clients)->response()->getData(true));
    }

    /**
     * قائمة العملاء المتصلين حالياً، من الـ cache الذي يُحدَّث كل دقيقة
     * عبر أمر clients:refresh-online-status — قراءة سريعة بدون استعلام
     * MikroTik حي (على عكس /clients/{client}/status لعميل واحد).
     */
    public function online(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        /** @var Agent $agent */
        $agent = $request->user();

        $onlineIds = Cache::get("agent_online_clients:{$agent->id}", []);

        $clients = $agent->clients()->whereIn('id', $onlineIds)->orderBy('fullname')->get();

        return response()->json(ClientResource::collection($clients));
    }

    public function store(StoreClientRequest $request, RadiusProvisioningService $radius): JsonResponse
    {
        $this->authorize('create', Client::class);

        /** @var Agent $agent */
        $agent = $request->user();

        $data = $request->validated();
        $data['start_date'] ??= now()->toDateString();
        $data['end_date'] ??= now()->addDays(30)->toDateString();

        $client = $agent->clients()->create($data);
        $radius->sync($client);

        ClientLog::create([
            'client_id' => $client->id,
            'action' => 'created',
            'old_value' => null,
            'new_value' => $client->username,
        ]);

        return response()->json(new ClientResource($client), 201);
    }

    public function show(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        return response()->json(new ClientResource($client));
    }

    public function update(UpdateClientRequest $request, Client $client, RadiusProvisioningService $radius): JsonResponse
    {
        $this->authorize('update', $client);

        $changes = [];
        $oldUsername = $client->username;

        foreach ($request->validated() as $field => $value) {
            if ((string) $client->{$field} !== (string) $value) {
                $changes[] = [
                    'client_id' => $client->id,
                    'action' => "{$field}_change",
                    'old_value' => (string) $client->{$field},
                    'new_value' => (string) $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        $client->update($request->validated());
        $client = $client->fresh();

        $radius->renameUsername($oldUsername, $client->username);
        $radius->sync($client);

        if ($changes !== []) {
            ClientLog::insert($changes);
        }

        return response()->json(new ClientResource($client));
    }

    public function destroy(Client $client, RadiusProvisioningService $radius): JsonResponse
    {
        $this->authorize('delete', $client);

        $radius->deprovision($client->username);
        $client->delete();

        return response()->json(status: 204);
    }

    /** يفعّل الاشتراك 30 يوماً من تاريخ الضغط (اليوم)، بغض النظر عن تاريخ الانتهاء الحالي. */
    public function renew(Client $client, RadiusProvisioningService $radius): JsonResponse
    {
        $this->authorize('update', $client);

        $newEndDate = now()->addDays(30)->toDateString();

        $client = $this->applyDateChange($client, 'renew', $newEndDate, $radius);

        return response()->json(new ClientResource($client));
    }

    /** يضبط تاريخ الانتهاء ليوم واحد فقط من الآن (تفعيل تجريبي). */
    public function trial(Client $client, RadiusProvisioningService $radius): JsonResponse
    {
        $this->authorize('update', $client);

        $newEndDate = now()->addDay()->toDateString();

        $client = $this->applyDateChange($client, 'trial_activation', $newEndDate, $radius);

        return response()->json(new ClientResource($client));
    }

    private function applyDateChange(Client $client, string $action, string $newEndDate, RadiusProvisioningService $radius): Client
    {
        ClientLog::create([
            'client_id' => $client->id,
            'action' => $action,
            'old_value' => $client->end_date?->toDateString(),
            'new_value' => $newEndDate,
        ]);

        $client->update(['end_date' => $newEndDate]);
        $client = $client->fresh();

        $radius->syncExpiration($client);

        return $client;
    }

    /** سجل تغييرات هذا المشترك (الأحدث أولاً). */
    public function logs(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $logs = $client->logs()->latest('created_at')->get(['id', 'client_id', 'action', 'old_value', 'new_value', 'created_at']);

        return response()->json($logs);
    }

    public function status(Client $client, MikrotikService $mikrotik): JsonResponse
    {
        $this->authorize('view', $client);

        try {
            return response()->json($mikrotik->connectionStatus($client));
        } catch (MikrotikConnectionException $e) {
            return response()->json([
                'connected' => false,
                'error' => $e->getMessage(),
            ], 503);
        }
    }
}
