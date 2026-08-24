<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Maps the existing `agents` table (isp_panel). Agents are the only
 * principals that authenticate against this API (via Sanctum tokens).
 */
class Agent extends Model
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'agents';

    protected $fillable = [
        'name',
        'username',
        'password',
        'mikrotik_host',
        'mikrotik_user',
        'mikrotik_pass',
        'mikrotik_port',
        'radius_secret',
        'balance',
        'electronic_payment_enabled',
        'pay_notify_message',
        'add_debt_notify_message',
        'renew_notify_message',
        'start_date',
        'end_date',
    ];

    protected $hidden = [
        'password',
        'mikrotik_pass',
        'radius_secret',
    ];

    protected function casts(): array
    {
        return [
            // TODO: switch back to a 'hashed' cast once migration to
            // isp-api is complete and passwords are re-hashed (see the
            // matching TODO in AuthController@login). While the `agents`
            // table is still shared with legacy isp-panel, values here are
            // plain text, so auto-hashing on write would corrupt them.
            'mikrotik_port' => 'integer',
            'balance' => 'integer',
            'electronic_payment_enabled' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function packagePrices(): HasMany
    {
        return $this->hasMany(PackagePrice::class);
    }

    public function isSubscriptionActive(): bool
    {
        $today = now()->toDateString();

        return $this->start_date?->toDateString() <= $today
            && $this->end_date?->toDateString() >= $today;
    }
}
