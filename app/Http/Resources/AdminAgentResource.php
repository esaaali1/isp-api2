<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * تمثيل الوكيل للوحة الإدارة فقط — يكشف عمداً كلمة المرور وبيانات
 * المايكروتك الكاملة (مخفية عادةً)، فهذا المسار محمي بصلاحية admin.
 *
 * @mixin \App\Models\Agent
 */
class AdminAgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'password' => $this->password,
            'mikrotik_host' => $this->mikrotik_host,
            'mikrotik_user' => $this->mikrotik_user,
            'mikrotik_pass' => $this->mikrotik_pass,
            'mikrotik_port' => $this->mikrotik_port,
            'wireguard_private_key' => $this->wireguard_private_key,
            'wireguard_public_key' => $this->wireguard_public_key,
            'clients_count' => $this->clients_count,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_active' => $this->isSubscriptionActive(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
