<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cabinet extends Model
{
    protected $fillable = ['station_id', 'code', 'online', 'last_seen_at'];

    protected $casts = [
        'online' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(CabinetCommand::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class);
    }
}
