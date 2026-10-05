<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 支付单与支付回执。
 *
 * 幂等设计:
 * - payments.order_id 唯一:一笔订单只有一张支付单;
 * - payment_receipts.receipt_no 唯一:同一回执重复推送(网关重试)
 *   只记录一次,已支付的支付单不会因重复回执再次扣费。
 */
class PaymentService
{
    public function createForOrder(Order $order): Payment
    {
        return Payment::firstOrCreate(
            ['order_id' => $order->id],
            [
                'payment_no' => 'P'.now()->format('ymd').strtoupper(Str::random(6)),
                'amount_cents' => $order->fee_cents ?? 0,
                'status' => Payment::STATUS_PENDING,
            ]
        );
    }

    /**
     * 模拟支付网关:用户付款后,网关向系统推送支付回执(webhook)。
     * $deliverTimes 模拟网关重试 —— 同一回执重复投递多次。
     *
     * @return array{receipt_no: string, deliveries: array<int, array{result: string}>}
     */
    public function mockCheckout(Payment $payment, int $deliverTimes = 1): array
    {
        $receipt = [
            'receipt_no' => 'RC'.strtoupper(Str::random(12)),
            'payment_no' => $payment->payment_no,
            'amount_cents' => $payment->amount_cents,
            'paid_at' => now()->toIso8601String(),
            'sign' => hash('sha256', $payment->payment_no.config('app.key')),
        ];

        $deliveries = [];
        for ($i = 0; $i < max(1, $deliverTimes); $i++) {
            $deliveries[] = $this->handleReceipt($receipt);
        }

        return ['receipt_no' => $receipt['receipt_no'], 'deliveries' => $deliveries];
    }

    /**
     * 处理支付回执(webhook 入口)。
     *
     * @param  array{receipt_no: string, payment_no: string, amount_cents: int}  $payload
     * @return array{result: string}
     */
    public function handleReceipt(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $receiptNo = $payload['receipt_no'] ?? null;
            if (! $receiptNo) {
                throw new DomainException('invalid_receipt', '回执缺少 receipt_no', 422);
            }

            // 同一回执重复推送:直接返回,不再触碰支付单
            $existing = PaymentReceipt::where('receipt_no', $receiptNo)->first();
            if ($existing) {
                return ['result' => PaymentReceipt::RESULT_DUPLICATE];
            }

            $payment = Payment::where('payment_no', $payload['payment_no'] ?? '')
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                $this->recordReceipt($payload, PaymentReceipt::RESULT_MISMATCH);

                return ['result' => PaymentReceipt::RESULT_MISMATCH];
            }

            if ($payment->status === Payment::STATUS_PAID) {
                // 已支付:重复回执不能导致再次收费
                $this->recordReceipt($payload, PaymentReceipt::RESULT_DUPLICATE);

                return ['result' => PaymentReceipt::RESULT_DUPLICATE];
            }

            if ((int) $payload['amount_cents'] !== (int) $payment->amount_cents) {
                $this->recordReceipt($payload, PaymentReceipt::RESULT_MISMATCH);

                return ['result' => PaymentReceipt::RESULT_MISMATCH];
            }

            $payment->status = Payment::STATUS_PAID;
            $payment->paid_at = now();
            $payment->save();

            $this->recordReceipt($payload, PaymentReceipt::RESULT_APPLIED);

            return ['result' => PaymentReceipt::RESULT_APPLIED];
        });
    }

    private function recordReceipt(array $payload, string $result): void
    {
        PaymentReceipt::create([
            'receipt_no' => $payload['receipt_no'],
            'payment_no' => $payload['payment_no'] ?? '',
            'amount_cents' => $payload['amount_cents'] ?? 0,
            'result' => $result,
            'payload' => $payload,
        ]);
    }
}
