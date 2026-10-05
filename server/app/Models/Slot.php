<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Slot extends Model
{
    public const STATE_EMPTY = 'empty';
    public const STATE_OCCUPIED = 'occupied';

    protected $fillable = [
        'cabinet_id', 'label', 'state', 'umbrella_id', 'reserved_by_return_id',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    public function umbrella(): BelongsTo
    {
        return $this->belongsTo(Umbrella::class);
    }

    public function isAvailableForReturn(): bool
    {
        return $this->state === self::STATE_EMPTY && $this->reserved_by_return_id === null;
    }
}
