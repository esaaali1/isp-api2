<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\Client;

class ClientPolicy
{
    /** Every authenticated agent may list their own clients. */
    public function viewAny(Agent $agent): bool
    {
        return true;
    }

    /** Every authenticated agent may create clients under their own account. */
    public function create(Agent $agent): bool
    {
        return true;
    }

    public function view(Agent $agent, Client $client): bool
    {
        return $agent->id === $client->agent_id;
    }

    public function update(Agent $agent, Client $client): bool
    {
        return $agent->id === $client->agent_id;
    }

    public function delete(Agent $agent, Client $client): bool
    {
        return $agent->id === $client->agent_id;
    }
}
