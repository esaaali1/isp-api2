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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function store(StoreClientRequest $request): JsonResponse
    {
        $this->authorize('create', Client::class);

        /** @var Agent $agent */
        $agent = $request->user();

        $client = $agent->clients()->create($request->validated());

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

    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $changes = [];

        foreach ($request->validated() as $field => $value) {
            if ((string) $client->{$field} !== (string) $value) {
                $changes[] = [
                    'client_id' => $client->id,
                    'action' => "updated:{$field}",
                    'old_value' => (string) $client->{$field},
                    'new_value' => (string) $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        $client->update($request->validated());

        if ($changes !== []) {
            ClientLog::insert($changes);
        }

        return response()->json(new ClientResource($client->fresh()));
    }

    public function destroy(Client $client): JsonResponse
    {
        $this->authorize('delete', $client);

        $client->delete();

        return response()->json(status: 204);
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
