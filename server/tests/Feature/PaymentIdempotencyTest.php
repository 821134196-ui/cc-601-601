<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use Tests\UmbrellaFlowTestCase;

/**
 * 支付幂等：重复回执不得重复收费。
 */
class PaymentIdempotencyTest extends UmbrellaFlowTestCase
{
    private function completedOrderWithFee(): Order
    {
        $order = $this->borrowAndTake(1);
        $this->travel(2)->hours();
        $this->initiateReturn($order, 2)->assertOk();
        $this->placeIntoCabinet(2, $order);

        return $order->fresh();
    }

    public function test_duplicate_receipt_does_not_charge_twice(): void
    {
        $order = $this->completedOrderWithFee();
        $this->assertSame(200, $order->fee_cents);

        $txn = $this->postJson('/api/payments/checkout', ['order_id' => $order->id])->assertOk();
        $payload = [
            'order_id' => $order->id,
            'transaction_id' => $txn->json('transaction_id'),
            'amount_cents' => $txn->json('amount_cents'),
        ];

        $this->postJson('/api/payments/confirm', $payload)->assertCreated()->assertJson(['duplicate' => false]);
        $this->assertSame('paid', $order->fresh()->payment_status);

        // 网关重发同一回执：判重，不重复收费
        $this->postJson('/api/payments/confirm', $payload)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, Payment::count(), '重复回执不得产生第二笔支付');
        $this->assertSame(200, Payment::sum('amount_cents'), '只应收取一次费用');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_new_transaction_on_paid_order_is_rejected(): void
    {
        $order = $this->completedOrderWithFee();
        $txn = $this->postJson('/api/payments/checkout', ['order_id' => $order->id]);
        $this->postJson('/api/payments/confirm', [
            'order_id' => $order->id,
            'transaction_id' => $txn->json('transaction_id'),
            'amount_cents' => 200,
        ])->assertCreated();

        // 已支付订单收到另一笔交易：拒绝
        $this->postJson('/api/payments/confirm', [
            'order_id' => $order->id,
            'transaction_id' => 'TXN-ANOTHER-ONE',
            'amount_cents' => 200,
        ])->assertStatus(422);

        $this->assertSame(1, Payment::count());
        $this->assertSame(200, Payment::sum('amount_cents'));
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $order = $this->completedOrderWithFee();

        $this->postJson('/api/payments/confirm', [
            'order_id' => $order->id,
            'transaction_id' => 'TXN-WRONG-AMOUNT',
            'amount_cents' => 999,
        ])->assertStatus(422);

        $this->assertSame(0, Payment::count());
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_unfinished_order_cannot_be_paid(): void
    {
        $order = $this->borrowAndTake(1);

        $this->postJson('/api/payments/confirm', [
            'order_id' => $order->id,
            'transaction_id' => 'TXN-TOO-EARLY',
            'amount_cents' => 0,
        ])->assertStatus(422);

        $this->assertSame(0, Payment::count());
    }
}
