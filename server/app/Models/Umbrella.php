<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Umbrella extends Model
{
    protected $fillable = ['code', 'status', 'station_id', 'slot_id'];

    public const STATUS_IN_SLOT = 'in_slot';
    public const STATUS_BORROWED = 'borrowed';
    public const STATUS_CLEANING = 'cleaning';
    public const STATUS_REPAIR = 'repair';
    public const STATUS_LOST = 'lost';

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /** 是否可被借出：只有在柜且未被标记清洁/维修/丢失的伞可借 */
    public function isBorrowable(): bool
    {
        return $this->status === self::STATUS_IN_SLOT && $this->slot_id !== null;
    }
}
