<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEvent extends Model
{
    public const TYPE_DOOR_OPENED = 'door_opened';
    public const TYPE_DOOR_CLOSED = 'door_closed';
    public const TYPE_DISPENSED = 'umbrella_dispensed';
    public const TYPE_INSERTED = 'umbrella_inserted';

    public const RESULT_APPLIED = 'applied';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_CONFLICT = 'conflict';

    protected $fillable = [
        'event_uid', 'cabinet_id', 'slot_id', 'umbrella_id', 'type',
        'device_seq', 'occurred_at', 'received_at', 'result', 'note', 'payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'payload' => 'array',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    public function umbrella(): BelongsTo
    {
        return $this->belongsTo(Umbrella::class);
    }
}
