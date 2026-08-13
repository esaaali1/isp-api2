<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Maps the existing `nas` table (isp_panel) — FreeRADIUS NAS/router clients list. No timestamps. */
class Nas extends Model
{
    protected $table = 'nas';

    public $timestamps = false;

    protected $fillable = [
        'nasname',
        'shortname',
        'type',
        'secret',
        'description',
    ];

    protected $hidden = [
        'secret',
    ];

    /** Matched by IP/hostname value, not a DB-level FK. */
    public function accountingSessions(): HasMany
    {
        return $this->hasMany(RadAcct::class, 'nasipaddress', 'nasname');
    }
}
