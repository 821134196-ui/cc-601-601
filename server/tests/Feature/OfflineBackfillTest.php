<?php

namespace Tests\Feature;

use App\Models\DeviceEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Slot;
use App\Models\Umbrella;
use Tests\TestCase;

/**
 * 柜机离线后补传事件:服务端按 device_seq 排序核对,
 * 重复补传(event_uid 相同)全部去重,状态只应用一次。
 */
class OfflineBackfillTest extends TestCase
{
    public function test_offline_events_backfilled_in_order_and_deduped(): void
    {
        ['station' => $stationA, 'cabinet' => $cabinetA] = $this->makeStation('ST-A', 3, 1);
        ['station' => $stationB, 'cabinet' => $cabinetB] = $this->makeStation('ST-B', 2, 0);

        // 借伞(在线)
        ['order_no' => $orderNo, 'umbrella_code' => $umbrellaCode] =
            $this->borrowUmbrella($stationA, $cabinetA, borrowedAt: '2026-10-01T08:00:00+00:00');

        // B 站柜机离线:用户仍发起还伞,柜机本地产生事件但无法上传
        $session = $this->postJson('/api/returns', ['order_no' => $orderNo, 'station_code' => 'ST-B'])
            ->assertCreated();
        $slotLabel = $session->json('slot_label');

        $offlineEvents = [
            ['event_uid' => 'E-OFF-1', 'cabinet_code' => $cabinetB->code, 'type' => 'door_opened',
                'slot_label' => $slotLabel, 'umbrella_code' => null, 'device_seq' => 1,
                'occurred_at' => '2026-10-01T08:20:00+00:00'],
            ['event_uid' => 'E-OFF-2', 'cabinet_code' => $cabinetB->code, 'type' => 'umbrella_inserted',
                'slot_label' => $slotLabel, 'umbrella_code' => $umbrellaCode, 'device_seq' => 2,
                'occurred_at' => '2026-10-01T08:20:05+00:00'],
            ['event_uid' => 'E-OFF-3', 'cabinet_code' => $cabinetB->code, 'type' => 'door_closed',
                'slot_label' => $slotLabel, 'umbrella_code' => null, 'device_seq' => 3,
                'occurred_at' => '2026-10-01T08:20:08+00:00'],
        ];

        // 故意乱序提交,服务端应按 device_seq 排序后应用
        $shuffled = [$offlineEvents[2], $offlineEvents[0], $offlineEvents[1]];
        $results = $this->postJson('/api/device/events/batch', ['events' => $shuffled])
            ->assertOk()->json('results');

        $this->assertSame(['applied', 'applied', 'applied'], array_column($results, 'result'));

        $order = Order::where('order_no', $orderNo)->firstOrFail();
        $this->assertSame('completed', $order->status);
        $this->assertSame('2026-10-01T08:20:08+00:00', $order->returned_at->toIso8601String());
        $this->assertSame(50, $order->fee_cents); // 20 分钟 → 1 单元

        // 柜机恢复后重试,同一批事件再次补传 → 全部判重,状态不再变化
        $again = $this->postJson('/api/device/events/batch', ['events' => $offlineEvents])
            ->assertOk()->json('results');
        $this->assertSame(['duplicate', 'duplicate', 'duplicate'], array_column($again, 'result'));

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Umbrella::where('status', 'in_slot')->count());
        $this->assertSame(3, DeviceEvent::where('cabinet_id', $cabinetB->id)->count());

        $order->refresh();
        $this->assertSame(50, $order->fee_cents);
        $this->assertTrue($this->getJson('/api/admin/reconcile')->json('consistent'));
    }

    public function test_conflicting_backfilled_event_is_recorded_without_side_effects(): void
    {
        ['station' => $stationA, 'cabinet' => $cabinetA] = $this->makeStation('ST-A', 2, 1);
        ['cabinet' => $cabinetB] = $this->makeStation('ST-B', 2, 0);

        ['order_no' => $orderNo, 'umbrella_code' => $umbrellaCode] =
            $this->borrowUmbrella($stationA, $cabinetA);

        // 补传一条"雨伞入柜"事件,但 B 站并没有该订单的还伞会话 → 冲突,不应用
        $results = $this->postJson('/api/device/events/batch', ['events' => [[
            'event_uid' => 'E-GHOST-1',
            'cabinet_code' => $cabinetB->code,
            'type' => 'umbrella_inserted',
            'slot_label' => 'A01',
            'umbrella_code' => $umbrellaCode,
            'device_seq' => 9,
            'occurred_at' => '2026-10-01T09:00:00+00:00',
        ]]])->assertOk()->json('results');

        $this->assertSame('conflict', $results[0]['result']);

        // 事件留痕可核对,但订单与柜位不受影响
        $this->assertDatabaseHas('device_events', ['event_uid' => 'E-GHOST-1', 'result' => 'conflict']);
        $this->assertSame('borrowed', Order::where('order_no', $orderNo)->firstOrFail()->status);
        $this->assertSame(2, Slot::where('cabinet_id', $cabinetB->id)->where('state', 'empty')->count());
        $this->assertTrue($this->getJson('/api/admin/reconcile')->json('consistent'));
    }
}
