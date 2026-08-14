<?php

namespace App\Services;

use App\Exceptions\MikrotikConnectionException;
use App\Models\Agent;
use App\Models\Client;
use App\Services\Mikrotik\RouterOsApiClient;

/**
 * Live connection status for a client, read directly from that client's
 * agent's MikroTik router (PPPoE and Hotspot active tables), not from the
 * radacct table — this reflects the router's real-time state.
 */
class MikrotikService
{
    public function __construct(
        private readonly float $timeout = 3.0,
    ) {
    }

    /**
     * @return array{
     *     connected: bool,
     *     type: 'pppoe'|'hotspot'|null,
     *     address: string|null,
     *     uptime: string|null,
     *     caller_id: string|null,
     *     bytes_in: int|null,
     *     bytes_out: int|null,
     * }
     */
    public function connectionStatus(Client $client): array
    {
        $agent = $client->agent;

        $offline = [
            'connected' => false,
            'type' => null,
            'address' => null,
            'uptime' => null,
            'caller_id' => null,
            'bytes_in' => null,
            'bytes_out' => null,
        ];

        if (! $agent || ! $agent->mikrotik_host || ! $agent->mikrotik_user) {
            return $offline;
        }

        $api = $this->connect($agent);

        try {
            $ppp = $api->query('/ppp/active/print', ["?name={$client->username}"]);

            if ($ppp !== []) {
                $row = $ppp[0];

                return [
                    'connected' => true,
                    'type' => 'pppoe',
                    'address' => $row['address'] ?? null,
                    'uptime' => $row['uptime'] ?? null,
                    'caller_id' => $row['caller-id'] ?? null,
                    'bytes_in' => null,
                    'bytes_out' => null,
                ];
            }

            $hotspot = $api->query('/ip/hotspot/active/print', ["?user={$client->username}"]);

            if ($hotspot !== []) {
                $row = $hotspot[0];
                $bytesIn = null;
                $bytesOut = null;

                if (isset($row['bytes-in'], $row['bytes-out'])) {
                    $bytesIn = (int) $row['bytes-in'];
                    $bytesOut = (int) $row['bytes-out'];
                }

                return [
                    'connected' => true,
                    'type' => 'hotspot',
                    'address' => $row['address'] ?? null,
                    'uptime' => $row['uptime'] ?? null,
                    'caller_id' => $row['mac-address'] ?? null,
                    'bytes_in' => $bytesIn,
                    'bytes_out' => $bytesOut,
                ];
            }

            return $offline;
        } finally {
            $api->close();
        }
    }

    /**
     * أسماء مستخدمي كل الجلسات المتصلة الآن (PPPoE + Hotspot) على راوتر
     * هذا الوكيل، باتصال واحد يُعاد استخدامه لكل عملائه — بعكس
     * connectionStatus() التي تفتح اتصالاً جديداً لكل عميل على حدة (مناسب
     * لفحص عميل واحد، لكنه بطيء جداً عند تكراره لعشرات العملاء).
     *
     * @return list<string>
     */
    public function onlineUsernamesForAgent(Agent $agent): array
    {
        if (! $agent->mikrotik_host || ! $agent->mikrotik_user) {
            return [];
        }

        try {
            $api = $this->connect($agent);
        } catch (MikrotikConnectionException) {
            return [];
        }

        try {
            $usernames = [];

            foreach ($api->query('/ppp/active/print') as $row) {
                if (isset($row['name'])) {
                    $usernames[] = $row['name'];
                }
            }

            foreach ($api->query('/ip/hotspot/active/print') as $row) {
                if (isset($row['user'])) {
                    $usernames[] = $row['user'];
                }
            }

            return $usernames;
        } catch (MikrotikConnectionException) {
            return [];
        } finally {
            $api->close();
        }
    }

    private function connect(Agent $agent): RouterOsApiClient
    {
        if (! $agent->mikrotik_pass) {
            throw new MikrotikConnectionException('بيانات دخول MikroTik غير مكتملة لهذا الوكيل.');
        }

        $api = new RouterOsApiClient(
            host: $agent->mikrotik_host,
            port: $agent->mikrotik_port ?: 8728,
            timeout: $this->timeout,
        );

        $api->connect($agent->mikrotik_user, $agent->mikrotik_pass);

        return $api;
    }
}
