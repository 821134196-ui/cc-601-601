<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CabinetCommand extends Model
{
    public const TYPE_OPEN_DOOR = 'open_door';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'cabinet_id', 'slot_id', 'type', 'status',
        'ref_type', 'ref_id', 'payload', 'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }
}
