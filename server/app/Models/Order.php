<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    public const STATUS_DISPENSING = 'dispensing'; // 已创建,等待柜门实际放出雨伞
    public const STATUS_BORROWED = 'borrowed';     // 已借出,计费中
    public const STATUS_RETURNING = 'returning';   // 还伞会话进行中
    public const STATUS_COMPLETED = 'completed';   // 已归还
    public const STATUS_CANCELLED = 'cancelled';   // 已取消(放伞超时等)

    protected $fillable = [
        'order_no', 'user_name', 'umbrella_id', 'borrow_station_id',
        'return_station_id', 'status', 'borrowed_at', 'returned_at', 'fee_cents',
    ];

    protected $casts = [
        'borrowed_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

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

    public function returnSessions(): HasMany
    {
        return $this->hasMany(ReturnSession::class);
    }

    public function activeReturnSession(): HasOne
    {
        return $this->hasOne(ReturnSession::class)->where('status', ReturnSession::STATUS_ACTIVE);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
