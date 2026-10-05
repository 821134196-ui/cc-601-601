<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Slot extends Model
{
    protected $fillable = ['station_id', 'label', 'status', 'umbrella_id'];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function umbrella(): BelongsTo
    {
        return $this->belongsTo(Umbrella::class);
    }
}
