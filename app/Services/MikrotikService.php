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
     * باتصال واحد فقط بهذا الوكيل (يُعاد استخدامه لكل عملائه، بعكس
     * connectionStatus() التي تفتح اتصالاً جديداً لكل عميل): تجلب كل
     * الجلسات النشطة (PPPoE + Hotspot)، وأي جلسة تخص مستخدماً من
     * $expiredUsernames تُفصل فوراً عبر أمر RouterOS مباشر بدل تركها
     * متصلة حتى يقطعها العميل بنفسه — ثم تعيد أسماء المتصلين الفعليين
     * (بعد أي فصل) لتخزينهم في الـ cache.
     *
     * @param  list<string>  $expiredUsernames
     * @return list<string>
     */
    public function refreshAgentSessions(Agent $agent, array $expiredUsernames): array
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
            $expired = array_flip($expiredUsernames);
            $onlineUsernames = [];

            foreach ($api->query('/ppp/active/print') as $row) {
                $username = $row['name'] ?? null;
                if ($username === null) {
                    continue;
                }

                if (isset($expired[$username]) && isset($row['.id'])) {
                    $api->remove('/ppp/active/remove', $row['.id']);
                    continue;
                }

                $onlineUsernames[] = $username;
            }

            foreach ($api->query('/ip/hotspot/active/print') as $row) {
                $username = $row['user'] ?? null;
                if ($username === null) {
                    continue;
                }

                if (isset($expired[$username]) && isset($row['.id'])) {
                    $api->remove('/ip/hotspot/active/remove', $row['.id']);
                    continue;
                }

                $onlineUsernames[] = $username;
            }

            return $onlineUsernames;
        } catch (MikrotikConnectionException) {
            return [];
        } finally {
            $api->close();
        }
    }

    /**
     * إحصائيات جهاز المايكروتك الخاص بالوكيل: هوية الجهاز وموديله
     * ووقت تشغيله، ومنافذ الايثرنت السلكية الفعلية المخصصة للسكتورات
     * فقط — أي المنافذ الأعضاء في bridge1 (مستبعداً منفذ WAN، عادة
     * ether1، الذي ليس عضواً في الجسر). لا تُجلب هنا أي بيانات عن
     * الأجهزة المتصلة خلف هذه المنافذ (كانت النسخة السابقة تعتمد على
     * /ip/neighbor/print وهذا كان يُظهر خطأً أجهزة مشتركين متصلين من
     * خلف السكتورات وليس فقط أجهزة البث نفسها).
     *
     * @return array{
     *     online: bool,
     *     router: array{identity: ?string, model: ?string, serial_number: ?string, firmware: ?string, os_version: ?string, uptime: ?string, cpu_load: ?int, free_memory: ?int, total_memory: ?int}|null,
     *     ports: list<array{name: string, running: bool, mac_address: ?string, last_link_up_time: ?string}>,
     * }
     */
    public function routerStatistics(Agent $agent): array
    {
        $empty = ['online' => false, 'router' => null, 'ports' => []];

        if (! $agent->mikrotik_host || ! $agent->mikrotik_user) {
            return $empty;
        }

        try {
            $api = $this->connect($agent);
        } catch (MikrotikConnectionException) {
            return $empty;
        }

        try {
            $identity = $api->query('/system/identity/print')[0] ?? [];
            $resource = $api->query('/system/resource/print')[0] ?? [];
            $routerboard = $api->query('/system/routerboard/print')[0] ?? [];

            $router = [
                'identity' => $identity['name'] ?? null,
                'model' => $routerboard['model'] ?? $resource['board-name'] ?? null,
                'serial_number' => $routerboard['serial-number'] ?? null,
                'firmware' => $routerboard['current-firmware'] ?? null,
                'os_version' => $resource['version'] ?? null,
                'uptime' => $resource['uptime'] ?? null,
                'cpu_load' => isset($resource['cpu-load']) ? (int) $resource['cpu-load'] : null,
                'free_memory' => isset($resource['free-memory']) ? (int) $resource['free-memory'] : null,
                'total_memory' => isset($resource['total-memory']) ? (int) $resource['total-memory'] : null,
            ];

            $bridgeMembers = [];
            foreach ($api->query('/interface/bridge/port/print') as $row) {
                if (isset($row['interface'])) {
                    $bridgeMembers[$row['interface']] = true;
                }
            }

            $ports = [];
            foreach ($api->query('/interface/print', ['?type=ether']) as $row) {
                $name = $row['name'] ?? '';

                if (! isset($bridgeMembers[$name])) {
                    continue;
                }

                $ports[] = [
                    'name' => $name,
                    'running' => ($row['running'] ?? 'false') === 'true',
                    'mac_address' => $row['mac-address'] ?? null,
                    'last_link_up_time' => $row['last-link-up-time'] ?? null,
                ];
            }

            return [
                'online' => true,
                'router' => $router,
                'ports' => $ports,
            ];
        } catch (MikrotikConnectionException) {
            return $empty;
        } finally {
            $api->close();
        }
    }

    /**
     * التدفّق اللحظي (بت/ثانية استقبالاً وإرسالاً) لمجموعة منافذ محدّدة،
     * بلقطة واحدة عبر أمر RouterOS /interface/monitor-traffic (once=) —
     * يُستدعى دورياً كل بضع ثوانٍ من الواجهة لتحديث أرقام السرعة الحية
     * لكل منفذ سكتور، دون إعادة جلب بقية إحصائيات الراوتر.
     *
     * @param  list<string>  $interfaceNames
     * @return list<array{name: string, rx_bps: int, tx_bps: int}>
     */
    public function portsTraffic(Agent $agent, array $interfaceNames): array
    {
        if ($interfaceNames === [] || ! $agent->mikrotik_host || ! $agent->mikrotik_user) {
            return [];
        }

        try {
            $api = $this->connect($agent);
        } catch (MikrotikConnectionException) {
            return [];
        }

        try {
            $interfaceList = implode(',', $interfaceNames);
            $rows = $api->query('/interface/monitor-traffic', ["=interface={$interfaceList}", '=once=']);

            return array_map(fn (array $row) => [
                'name' => $row['name'] ?? '',
                'rx_bps' => (int) ($row['rx-bits-per-second'] ?? 0),
                'tx_bps' => (int) ($row['tx-bits-per-second'] ?? 0),
            ], $rows);
        } catch (MikrotikConnectionException) {
            return [];
        } finally {
            $api->close();
        }
    }

    /**
     * تحقق سريع مما إذا كان جهاز مايكروتك هذا الوكيل قابلاً للاتصال به
     * الآن — يُستخدم في لوحة الإدارة لتحديد "الوكلاء المتصلين" دون جلب
     * كل إحصائيات الجهاز (أخف من routerStatistics عند فحص عدة وكلاء
     * تباعاً).
     */
    public function pingAgent(Agent $agent): bool
    {
        if (! $agent->mikrotik_host || ! $agent->mikrotik_user) {
            return false;
        }

        try {
            $api = $this->connect($agent);
        } catch (MikrotikConnectionException) {
            return false;
        }

        try {
            $api->query('/system/identity/print');

            return true;
        } catch (MikrotikConnectionException) {
            return false;
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
