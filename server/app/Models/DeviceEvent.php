<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceEvent extends Model
{
    protected $fillable = [
        'event_uid', 'station_id', 'slot_id', 'umbrella_id', 'order_id',
        'type', 'payload', 'occurred_at', 'processed_at', 'result',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public const TYPE_DISPENSED = 'umbrella_dispensed'; // 柜门放出雨伞（用户取走）
    public const TYPE_RETURNED = 'umbrella_returned';   // 柜门关闭且设备确认雨伞入柜
}
