<?php

namespace Tests\Feature;

use App\Models\DeviceEvent;
use App\Models\Order;
use App\Models\SimOutbox;
use App\Models\Station;
use Tests\UmbrellaFlowTestCase;

/**
 * 柜机离线：事件暂存柜机本地，恢复在线后批量补传；
 * 补传可重复执行，事件记录逐条核对，状态只生效一次。
 */
class OfflineBackfillTest extends UmbrellaFlowTestCase
{
    public function test_offline_events_are_queued_then_backfilled(): void
    {
        // 柜机断网
        $this->postJson('/api/cabinet/1/online', ['online' => false])->assertOk();

        // 离线期间借伞：开门指令照常下发，但取伞事件进柜机本地队列
        $order = $this->borrowOnly(1);
        $this->takeFromCabinet(1, $order);

        $this->assertSame(Order::STATUS_DISPENSING, $order->fresh()->status, '事件未送达，订单不应生效');
        $this->assertSame(1, SimOutbox::whereNull('sent_at')->count());
        $this->assertSame(0, DeviceEvent::count(), '离线期间服务端不应有事件记录');

        // 1 小时后柜机恢复在线并补传
        $this->travel(1)->hours();
        $this->postJson('/api/cabinet/1/online', ['online' => true]);
        $res = $this->postJson('/api/cabinet/1/sync')->assertOk();
        $this->assertCount(1, $res->json('results'));
        $this->assertFalse($res->json('results.0.duplicate'));

        // 订单生效，且计费起点 = 事件实际发生时间（1 小时前），而非补传到达时间
        $order->refresh();
        $this->assertSame(Order::STATUS_ACTIVE, $order->status);
        $this->assertSame(now()->subHour()->toDateTimeString(), $order->borrowed_at->toDateTimeString());
        $this->assertSame(0, SimOutbox::whereNull('sent_at')->count());
        $this->assertGlobalConsistency();
    }

    public function test_offline_return_and_repeated_backfill_is_idempotent(): void
    {
        $order = $this->borrowAndTake(1);

        // 河畔广场站柜机离线，归还事件积压在柜机端
        $this->postJson('/api/cabinet/2/online', ['online' => false]);
        $this->travel(90)->minutes();
        $this->initiateReturn($order, 2)->assertOk();
        $this->placeIntoCabinet(2, $order);

        $this->assertSame(Order::STATUS_RETURNING, $order->fresh()->status, '归还事件未送达，订单不应完成');
        $this->assertSame(1, Station::find(2)->availableCount(), '事件未送达前，服务端数量保持不变');

        // 恢复在线，补传
        $this->postJson('/api/cabinet/2/online', ['online' => true]);
        $this->postJson('/api/cabinet/2/sync')->assertOk();

        $order->refresh();
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        // 计费终点 = 柜机关门确认入柜的事件时间（离线期间发生，补传后按发生时间入账）
        $this->assertSame(now()->toDateTimeString(), $order->returned_at->toDateTimeString());
        $this->assertSame(now()->subMinutes(90)->toDateTimeString(), $order->borrowed_at->toDateTimeString());
        $this->assertSame(200, $order->fee_cents); // 90 分钟 → 2 小时档

        // 重复补传同一批事件（如柜机重发）：全部判重，状态不变
        $event = DeviceEvent::where('type', 'umbrella_returned')->firstOrFail();
        $res = $this->postJson('/api/device/events/batch', [
            'events' => [[
                'event_uid' => $event->event_uid,
                'type' => $event->type,
                'station_id' => $event->station_id,
                'payload' => $event->payload,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ]],
        ])->assertOk();
        $this->assertTrue($res->json('results.0.duplicate'));

        $this->assertSame(1, DeviceEvent::where('type', 'umbrella_returned')->count());
        $this->assertSame(200, $order->fresh()->fee_cents, '重复补传不得改变费用');
        $this->assertSame(2, Station::find(2)->availableCount());
        $this->assertSame(1, Station::find(2)->emptySlotCount());
        $this->assertGlobalConsistency();
    }
}
