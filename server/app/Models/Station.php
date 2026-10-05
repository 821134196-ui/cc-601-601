<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    protected $fillable = ['name', 'code', 'address', 'lat', 'lng', 'cabinet_online'];

    protected $casts = ['cabinet_online' => 'boolean'];

    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class);
    }

    /** 可借雨伞数：在柜且状态可借（排除清洁/维修/丢失） */
    public function availableCount(): int
    {
        return Umbrella::where('station_id', $this->id)
            ->where('status', 'in_slot')
            ->whereHas('slot', fn ($q) => $q->where('status', 'occupied'))
            ->count();
    }

    /** 空柜位数 */
    public function emptySlotCount(): int
    {
        return $this->slots()->where('status', 'empty')->count();
    }
}
