<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Maps the existing `radgroupreply` table (isp_panel) — FreeRADIUS per-group reply attributes (e.g. bandwidth limits per package). No timestamps. */
class RadGroupReply extends Model
{
    protected $table = 'radgroupreply';

    public $timestamps = false;

    protected $fillable = [
        'groupname',
        'attribute',
        'op',
        'value',
    ];
}
