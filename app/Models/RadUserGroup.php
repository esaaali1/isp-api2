<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps the existing `radusergroup` table (isp_panel) — FreeRADIUS group
 * membership per user. This table has NO primary key in the schema (only
 * a composite of username+groupname+priority and an index on username),
 * which is standard for FreeRADIUS. `username` is declared as the Eloquent
 * primary key only so the model boots; it is not unique per row, so avoid
 * ->find()/->save() here and prefer explicit ->where(...) queries for
 * updates/deletes.
 */
class RadUserGroup extends Model
{
    protected $table = 'radusergroup';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    protected $fillable = [
        'username',
        'groupname',
        'priority',
    ];
}
