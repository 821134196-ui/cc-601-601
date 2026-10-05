<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use Tests\TestCase;

/**
 * 重复支付回执:网关重试导致同一回执重复推送,
 * 支付单只入账一次,不会重复收费。
 */
class PaymentIdempotencyTest extends TestCase
{
    private function completedOrderPayment(): Payment
    {
        ['station' => $stationA, 'cabinet' => $cabinetA] = $this->makeStation('ST-A', 2, 1);
        ['station' => $stationB, 'cabinet' => $cabinetB] = $this->makeStation('ST-B', 2, 0);

        ['order_no' => $orderNo, 'umbrella_code' => $umbrellaCode] =
            $this->borrowUmbrella($stationA, $cabinetA);
        // 20 分钟 → 1 个计费单元 → 50 分
        $this->returnUmbrella($orderNo, $stationB, $cabinetB, $umbrellaCode, '2026-10-01T10:20:00+00:00');

        return Payment::sole();
    }

    public function test_duplicate_receipts_do_not_double_charge(): void
    {
        $payment = $this->completedOrderPayment();

        // 模拟网关把同一回执推送 3 次
        $response = $this->postJson("/api/payments/{$payment->payment_no}/mock-pay", ['times' => 3])
            ->assertOk();

        $this->assertSame(
            ['applied', 'duplicate', 'duplicate'],
            array_column($response->json('deliveries'), 'result')
        );

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame(50, $payment->amount_cents); // 金额不变,只收一次

        // 回执只记录一条(按 receipt_no 去重)
        $this->assertSame(1, PaymentReceipt::count());
        $this->assertSame('applied', PaymentReceipt::sole()->result);
    }

    public function test_webhook_replay_and_second_receipt_for_paid_payment_are_ignored(): void
    {
        $payment = $this->completedOrderPayment();

        $receipt = [
            'receipt_no' => 'RC-TEST-1',
            'payment_no' => $payment->payment_no,
            'amount_cents' => $payment->amount_cents,
        ];

        $this->postJson('/api/webhooks/payment', $receipt)->assertOk()->assertJsonPath('result', 'applied');
        // 同一回执重放
        $this->postJson('/api/webhooks/payment', $receipt)->assertOk()->assertJsonPath('result', 'duplicate');
        // 已支付的支付单收到新回执(网关补发)→ 判重,不再收费
        $this->postJson('/api/webhooks/payment', array_merge($receipt, ['receipt_no' => 'RC-TEST-2']))
            ->assertOk()->assertJsonPath('result', 'duplicate');

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame(50, $payment->amount_cents);
        $this->assertSame(2, PaymentReceipt::count());
        $this->assertSame(1, Payment::where('status', 'paid')->count());
    }

    public function test_amount_mismatch_receipt_is_not_applied(): void
    {
        $payment = $this->completedOrderPayment();

        $this->postJson('/api/webhooks/payment', [
            'receipt_no' => 'RC-BAD-1',
            'payment_no' => $payment->payment_no,
            'amount_cents' => 9999,
        ])->assertOk()->assertJsonPath('result', 'mismatch');

        $this->assertSame('pending', $payment->fresh()->status);
    }
}
