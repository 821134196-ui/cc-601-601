<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Station extends Model
{
    protected $fillable = ['code', 'name', 'address'];

    public function cabinet(): HasOne
    {
        return $this->hasOne(Cabinet::class);
    }

    public function borrowOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'borrow_station_id');
    }
}
