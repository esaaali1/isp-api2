<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Maps the existing `radacct` table (isp_panel) — FreeRADIUS accounting sessions. No created_at/updated_at (uses its own acct* timestamp columns). */
class RadAcct extends Model
{
    protected $table = 'radacct';

    protected $primaryKey = 'radacctid';

    public $timestamps = false;

    protected $fillable = [
        'acctsessionid',
        'acctuniqueid',
        'username',
        'realm',
        'nasipaddress',
        'nasportid',
        'nasporttype',
        'acctstarttime',
        'acctupdatetime',
        'acctstoptime',
        'acctinterval',
        'acctsessiontime',
        'acctauthentic',
        'connectinfo_start',
        'connectinfo_stop',
        'acctinputoctets',
        'acctoutputoctets',
        'calledstationid',
        'callingstationid',
        'acctterminatecause',
        'servicetype',
        'framedprotocol',
        'framedipaddress',
    ];

    protected function casts(): array
    {
        return [
            'acctstarttime' => 'datetime',
            'acctupdatetime' => 'datetime',
            'acctstoptime' => 'datetime',
            'acctinterval' => 'integer',
            'acctsessiontime' => 'integer',
            'acctinputoctets' => 'integer',
            'acctoutputoctets' => 'integer',
        ];
    }

    /** True while the session has no stop time recorded, i.e. still connected. */
    public function isOpen(): bool
    {
        return is_null($this->acctstoptime);
    }

    /** Matched by IP/hostname value, not a DB-level FK. */
    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class, 'nasipaddress', 'nasname');
    }
}
