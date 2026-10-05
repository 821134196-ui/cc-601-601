<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SimOutbox extends Model
{
    protected $table = 'sim_outbox';

    protected $fillable = ['station_id', 'event_uid', 'type', 'payload', 'occurred_at', 'sent_at'];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
