<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * 记录支付回执（本地模拟支付网关回调）。
     *
     * 幂等：transaction_id 全局唯一，重复回执直接返回原支付记录，
     * 不会再次收费、不会改动订单状态。
     */
    public function recordReceipt(int $orderId, string $transactionId, int $amountCents, array $receipt = []): array
    {
        return DB::transaction(function () use ($orderId, $transactionId, $amountCents, $receipt) {
            $existing = Payment::where('transaction_id', $transactionId)->first();
            if ($existing) {
                return ['payment' => $existing, 'duplicate' => true];
            }

            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status !== Order::STATUS_COMPLETED) {
                throw new \RuntimeException('订单未完成，不能支付');
            }
            if ($order->payment_status === 'paid') {
                throw new \RuntimeException('订单已支付，重复回执被拒绝');
            }
            if ($amountCents !== $order->fee_cents) {
                throw new \RuntimeException("金额不符：应付 {$order->fee_cents} 分，实付 {$amountCents} 分");
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'amount_cents' => $amountCents,
                'status' => 'success',
                'receipt' => $receipt,
            ]);
            $order->update(['payment_status' => 'paid']);

            return ['payment' => $payment, 'duplicate' => false];
        });
    }
}
