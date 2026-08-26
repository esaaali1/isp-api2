<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل تدقيق للتغييرات التي يجريها المدير على حسابات الوكلاء (تعديل بياناته، تمديد اشتراكه، إلخ). */
class AgentLog extends Model
{
    protected $table = 'agent_logs';

    protected $fillable = [
        'agent_id',
        'action',
        'old_value',
        'new_value',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
