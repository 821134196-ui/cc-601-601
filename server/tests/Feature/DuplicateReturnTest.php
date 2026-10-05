<?php

namespace Tests\Feature;

use App\Models\DeviceEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Slot;
use App\Models\Umbrella;
use Tests\TestCase;

/**
 * 重复归还:同一物理归还动作被设备以不同 event_uid 重复上报,
 * 不能导致再次收费,也不能多释放/多占用一个柜位。
 */
class DuplicateReturnTest extends TestCase
{
    public function test_duplicate_return_events_do_not_double_charge_or_double_occupy(): void
    {
        ['station' => $stationA, 'cabinet' => $cabinetA] = $this->makeStation('ST-A', 3, 1);
        ['station' => $stationB, 'cabinet' => $cabinetB] = $this->makeStation('ST-B', 2, 0);

        ['order_no' => $orderNo, 'umbrella_code' => $umbrellaCode] =
            $this->borrowUmbrella($stationA, $cabinetA, borrowedAt: '2026-10-01T10:00:00+00:00');

        $session = $this->postJson('/api/returns', ['order_no' => $orderNo, 'station_code' => 'ST-B'])
            ->assertCreated();
        $slotLabel = $session->json('slot_label');

        // 正常归还
        $this->postEvent($cabinetB, 'umbrella_inserted', '2026-10-01T10:20:00+00:00', $slotLabel, $umbrellaCode)->assertOk();
        $this->postEvent($cabinetB, 'door_closed', '2026-10-01T10:20:03+00:00', $slotLabel)->assertOk();

        $order = Order::where('order_no', $orderNo)->firstOrFail();
        $this->assertSame('completed', $order->status);
        $this->assertSame(50, $order->fee_cents);
        $this->assertSame(1, Payment::count());

        // 设备故障重发:同一归还动作换 event_uid 再上报一轮
        $dup1 = $this->postEvent($cabinetB, 'umbrella_inserted', '2026-10-01T10:20:00+00:00', $slotLabel, $umbrellaCode);
        $dup1->assertOk()->assertJsonPath('result', 'duplicate');
        $dup2 = $this->postEvent($cabinetB, 'door_closed', '2026-10-01T10:20:03+00:00', $slotLabel);
        $dup2->assertOk();

        // 费用、支付单、柜位占用全部不变
        $order->refresh();
        $this->assertSame(50, $order->fee_cents);
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());

        $slot = Slot::where('cabinet_id', $cabinetB->id)->where('label', $slotLabel)->firstOrFail();
        $this->assertSame('occupied', $slot->state);
        $this->assertSame(1, Slot::where('cabinet_id', $cabinetB->id)->where('state', 'occupied')->count());
        $this->assertSame(1, Umbrella::where('status', 'in_slot')->count());

        $this->assertTrue($this->getJson('/api/admin/reconcile')->json('consistent'));
    }

    public function test_return_on_completed_order_is_rejected(): void
    {
        ['station' => $stationA, 'cabinet' => $cabinetA] = $this->makeStation('ST-A', 2, 1);
        ['station' => $stationB, 'cabinet' => $cabinetB] = $this->makeStation('ST-B', 2, 0);

        ['order_no' => $orderNo, 'umbrella_code' => $umbrellaCode] =
            $this->borrowUmbrella($stationA, $cabinetA);
        $this->returnUmbrella($orderNo, $stationB, $cabinetB, $umbrellaCode);

        // 已完成的订单再次发起还伞 → 拒绝,不会新占柜位
        $this->postJson('/api/returns', ['order_no' => $orderNo, 'station_code' => 'ST-B'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'order_not_returnable');

        $stats = collect($this->getJson('/api/stations')->json('stations'))->firstWhere('code', 'ST-B');
        $this->assertSame(1, $stats['empty_slots']);
        $this->assertSame(0, $stats['reserved_slots']);
    }
}
