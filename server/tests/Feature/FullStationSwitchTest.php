<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Slot;
use App\Models\Station;
use App\Models\Umbrella;
use Tests\UmbrellaFlowTestCase;

/**
 * 满柜换站：归还站已满时改选其他站，原借用计费起点继续保留。
 */
class FullStationSwitchTest extends UmbrellaFlowTestCase
{
    public function test_full_station_rejects_return_and_offers_alternatives(): void
    {
        $order = $this->borrowAndTake(1); // 阳光社区站借出
        $borrowedAt = $order->borrowed_at->copy();

        $this->travel(2)->hours();

        // 老街口站（id=3）初始满柜：2 柜位 2 伞
        $res = $this->initiateReturn($order, 3);
        $res->assertStatus(409)->assertJson(['station_full' => true]);

        $alternatives = collect($res->json('alternatives'));
        $this->assertTrue($alternatives->contains('id', 1));
        $this->assertTrue($alternatives->contains('id', 2));
        $this->assertFalse($alternatives->contains('id', 3), '满柜站点不应推荐自己');

        // 订单保持借用中，计费起点不变
        $order->refresh();
        $this->assertSame(Order::STATUS_ACTIVE, $order->status);
        $this->assertEquals($borrowedAt, $order->borrowed_at);
        $this->assertNull($order->return_station_id);
    }

    public function test_switching_station_keeps_original_billing_start(): void
    {
        $order = $this->borrowAndTake(1);
        $borrowedAt = $order->borrowed_at->copy();

        $this->travel(2)->hours();

        // 满柜被拒 → 改还河畔广场站（id=2）
        $this->initiateReturn($order, 3)->assertStatus(409);
        $this->initiateReturn($order, 2)->assertOk();
        $this->placeIntoCabinet(2, $order);

        $order->refresh();
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame(2, $order->return_station_id);
        // 计费起点 = 最初柜门放伞的事件时间，未因换站而改变
        $this->assertEquals($borrowedAt, $order->borrowed_at);
        // 2 小时 → 超过 30 分钟免费时长，按 2 小时 × 1 元计费
        $this->assertSame(200, $order->fee_cents);

        // 伞已入河畔广场站柜位，各站数量与物理状态一致
        $umbrella = Umbrella::findOrFail($order->umbrella_id);
        $this->assertSame('in_slot', $umbrella->status);
        $this->assertSame(2, $umbrella->station_id);
        $this->assertSame(2, Station::find(2)->availableCount());
        $this->assertSame(1, Station::find(2)->emptySlotCount());
        $this->assertSame(0, Station::find(3)->emptySlotCount(), '满柜站不受影响');
        $this->assertGlobalConsistency();
    }

    public function test_cancel_return_then_switch_station(): void
    {
        $order = $this->borrowAndTake(1);
        $borrowedAt = $order->borrowed_at->copy();

        // 先在 ST-B 发起归还（占用一个空位），随后取消改去 ST-A
        $this->initiateReturn($order, 2)->assertOk();
        $this->assertSame(1, Station::find(2)->emptySlotCount(), '归还中的柜位被预留');

        $this->postJson("/api/orders/{$order->id}/cancel-return")->assertOk();
        $order->refresh();
        $this->assertSame(Order::STATUS_ACTIVE, $order->status);
        $this->assertEquals($borrowedAt, $order->borrowed_at);
        $this->assertSame(2, Station::find(2)->emptySlotCount(), '取消归还后空位释放');

        $this->initiateReturn($order, 1)->assertOk();
        $this->placeIntoCabinet(1, $order);
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertGlobalConsistency();
    }
}
