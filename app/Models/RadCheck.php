<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Maps the existing `radcheck` table (isp_panel) — FreeRADIUS per-user check attributes (e.g. Cleartext-Password). No timestamps. */
class RadCheck extends Model
{
    protected $table = 'radcheck';

    public $timestamps = false;

    protected $fillable = [
        'username',
        'attribute',
        'op',
        'value',
    ];

    protected $hidden = [
        'value',
    ];
}
