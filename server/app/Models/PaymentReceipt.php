<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    public const RESULT_APPLIED = 'applied';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_MISMATCH = 'mismatch';

    protected $fillable = [
        'receipt_no', 'payment_no', 'amount_cents', 'result', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
