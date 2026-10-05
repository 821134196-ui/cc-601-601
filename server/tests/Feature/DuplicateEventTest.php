<?php

namespace Tests\Feature;

use App\Models\DeviceEvent;
use App\Models\Order;
use App\Models\Slot;
use App\Models\Station;
use Tests\UmbrellaFlowTestCase;

/**
 * 重复事件防护：
 *  - 同一 event_uid 重发 → 判重忽略；
 *  - 柜机故障用新 event_uid 重发同一物理动作 → 状态校验拒绝。
 * 两种情况下都不得重复释放/占用柜位、不得改变订单与计费。
 */
class DuplicateEventTest extends UmbrellaFlowTestCase
{
    public function test_same_event_uid_replayed_is_ignored(): void
    {
        $order = $this->borrowAndTake(1);
        $this->initiateReturn($order, 2)->assertOk();
        $this->placeIntoCabinet(2, $order);
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);

        $eventsBefore = DeviceEvent::count();
        $returned = DeviceEvent::where('type', 'umbrella_returned')->firstOrFail();

        // 网络重试：同一事件原样重发
        $res = $this->postJson('/api/device/events', [
            'event_uid' => $returned->event_uid,
            'type' => 'umbrella_returned',
            'station_id' => $returned->station_id,
            'payload' => $returned->payload,
            'occurred_at' => $returned->occurred_at->toIso8601String(),
        ]);
        $res->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame($eventsBefore, DeviceEvent::count(), '重复事件不应产生新记录');
        $this->assertGlobalConsistency();
    }

    public function test_duplicate_return_with_new_event_uid_does_not_occupy_another_slot(): void
    {
        $order = $this->borrowAndTake(1);
        $this->initiateReturn($order, 2)->assertOk();
        $this->placeIntoCabinet(2, $order);

        $order = $order->fresh();
        $fee = $order->fee_cents;
        $emptyBefore = Station::find(2)->emptySlotCount();
        $occupiedBefore = Slot::where('station_id', 2)->where('status', 'occupied')->count();

        // 柜机故障：用新 event_uid 再次上报"同一订单归还"
        $res = $this->postJson('/api/device/events', [
            'event_uid' => 'EVT-GLITCH-'.uniqid(),
            'type' => 'umbrella_returned',
            'station_id' => 2,
            'payload' => [
                'order_id' => $order->id,
                'slot_id' => $order->umbrella->slot_id,
                'umbrella_id' => $order->umbrella_id,
            ],
            'occurred_at' => now()->toIso8601String(),
        ]);
        $res->assertCreated();

        // 事件被记录（便于核对），但状态校验拒绝生效
        $event = DeviceEvent::where('event_uid', 'like', 'EVT-GLITCH-%')->firstOrFail();
        $this->assertStringStartsWith('rejected', $event->result);

        $order->refresh();
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame($fee, $order->fee_cents, '重复归不得改变费用');
        $this->assertSame($emptyBefore, Station::find(2)->emptySlotCount(), '不得多释放/占用柜位');
        $this->assertSame($occupiedBefore, Slot::where('station_id', 2)->where('status', 'occupied')->count());
        $this->assertGlobalConsistency();
    }

    public function test_duplicate_dispense_with_new_event_uid_does_not_free_another_slot(): void
    {
        $order = $this->borrowAndTake(1);
        $dispensed = DeviceEvent::where('type', 'umbrella_dispensed')->firstOrFail();
        $emptyBefore = Station::find(1)->emptySlotCount();

        // 柜机故障：用新 event_uid 再次上报"同一订单放伞"
        $this->postJson('/api/device/events', [
            'event_uid' => 'EVT-GLITCH-'.uniqid(),
            'type' => 'umbrella_dispensed',
            'station_id' => 1,
            'payload' => $dispensed->payload,
            'occurred_at' => now()->toIso8601String(),
        ])->assertCreated();

        $event = DeviceEvent::where('event_uid', 'like', 'EVT-GLITCH-%')->firstOrFail();
        $this->assertStringStartsWith('rejected', $event->result);
        $this->assertSame($emptyBefore, Station::find(1)->emptySlotCount(), '重复放伞不得多释放柜位');
        $this->assertSame(2, Station::find(1)->availableCount(), '可借数不变（借出 1 把后剩 2）');
        $this->assertGlobalConsistency();
    }
}
