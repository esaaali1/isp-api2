<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Maps the existing `clients` table (isp_panel). Each client belongs to
 * exactly one agent and shares its `username` with the radcheck/radreply/
 * radusergroup/radacct tables (no DB-level FK there, just matching values).
 *
 * Unlike Agent, `password` here is deliberately NOT hidden: it's the
 * client's PPPoE/hotspot dial-up credential, which agents legitimately
 * need to read back and hand to the client (not a panel login secret).
 * ClientResource exposes it on purpose.
 */
class Client extends Model
{
    use HasFactory;

    protected $table = 'clients';

    protected $fillable = [
        'agent_id',
        'fullname',
        'username',
        'password',
        'phone',
        'package',
        'debt',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'debt' => 'integer',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ClientLog::class);
    }

    /** RADIUS check attributes for this client's username (e.g. Cleartext-Password). */
    public function radChecks(): HasMany
    {
        return $this->hasMany(RadCheck::class, 'username', 'username');
    }

    /** RADIUS reply attributes for this client's username (e.g. Framed-IP-Address). */
    public function radReplies(): HasMany
    {
        return $this->hasMany(RadReply::class, 'username', 'username');
    }

    /** RADIUS group memberships for this client's username. */
    public function radUserGroups(): HasMany
    {
        return $this->hasMany(RadUserGroup::class, 'username', 'username');
    }

    /** RADIUS accounting sessions for this client's username. */
    public function radAcctSessions(): HasMany
    {
        return $this->hasMany(RadAcct::class, 'username', 'username');
    }

    /** يقارن اللحظة الحالية كاملة (تاريخاً ووقتاً)، وليس التاريخ فقط. */
    public function isActive(): bool
    {
        $now = now();

        return $this->start_date?->lessThanOrEqualTo($now)
            && $this->end_date?->greaterThanOrEqualTo($now);
    }
}
