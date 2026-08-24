<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سعر باقة واحدة (بالدينار العراقي) خاص بوكيل معيّن — يغذّي لوحة "الديون". */
class PackagePrice extends Model
{
    protected $fillable = [
        'agent_id',
        'package',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
