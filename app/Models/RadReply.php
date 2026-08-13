<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Maps the existing `radreply` table (isp_panel) — FreeRADIUS per-user reply attributes (e.g. Framed-IP-Address). No timestamps. */
class RadReply extends Model
{
    protected $table = 'radreply';

    public $timestamps = false;

    protected $fillable = [
        'username',
        'attribute',
        'op',
        'value',
    ];
}
