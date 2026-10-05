<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'order_no', 'user_name', 'umbrella_id', 'borrow_station_id', 'return_station_id',
        'status', 'borrowed_at', 'returned_at', 'fee_cents', 'payment_status',
    ];

    protected $casts = [
        'borrowed_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public const STATUS_DISPENSING = 'dispensing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RETURNING = 'returning';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function umbrella(): BelongsTo
    {
        return $this->belongsTo(Umbrella::class);
    }

    public function borrowStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'borrow_station_id');
    }

    public function returnStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'return_station_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
