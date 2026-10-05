<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Umbrella extends Model
{
    public const STATUS_IN_SLOT = 'in_slot';       // 在架可借
    public const STATUS_DISPATCHING = 'dispatching'; // 已分配,等待柜门放出
    public const STATUS_BORROWED = 'borrowed';     // 借出中
    public const STATUS_CLEANING = 'cleaning';     // 清洁中
    public const STATUS_REPAIR = 'repair';         // 维修中
    public const STATUS_LOST = 'lost';             // 已丢失

    /** 不可借出的状态(维修中的雨伞不能借出,清洁中同理) */
    public const UNBORROWABLE = [
        self::STATUS_DISPATCHING,
        self::STATUS_BORROWED,
        self::STATUS_CLEANING,
        self::STATUS_REPAIR,
        self::STATUS_LOST,
    ];

    protected $fillable = ['code', 'status', 'slot_id'];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isBorrowable(): bool
    {
        return $this->status === self::STATUS_IN_SLOT && $this->slot_id !== null;
    }
}
