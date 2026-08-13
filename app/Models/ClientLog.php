<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Maps the existing `client_logs` table (isp_panel) — audit trail of changes made to a client. */
class ClientLog extends Model
{
    protected $table = 'client_logs';

    protected $fillable = [
        'client_id',
        'action',
        'old_value',
        'new_value',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
