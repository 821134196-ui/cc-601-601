<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Slot;
use App\Models\Umbrella;
use Tests\TestCase;

class BorrowFlowTest extends TestCase
{
    public function test_borrow_succeeds_only_when_door_actually_dispenses(): void
    {
        ['station' => $station, 'cabinet' => $cabinet, 'umbrellas' => [$umbrella]] = $this->makeStation('ST-A', 4, 2);

        // 下单后、柜门放伞前:订单是 dispensing,柜位仍占用,不计费
        $response = $this->postJson('/api/orders', ['user_name' => '张三', 'station_code' => 'ST-A']);
        $response->assertCreated();
        $orderNo = $response->json('order.order_no');

        $this->assertSame('dispensing', $response->json('order.status'));
        $this->assertNull($response->json('order.borrowed_at'));
        // 一把伞转为 dispatching(占位),另一把仍在架
        $this->assertSame(1, Umbrella::where('status', 'in_slot')->count());
        $this->assertSame(1, Umbrella::where('status', 'dispatching')->count());

        // 柜门实际放出雨伞的事件到达 → 订单生效,计费起点 = 事件时间
        $dispensedAt = '2026-10-01T10:00:00+00:00';
        $this->postEvent($cabinet, 'door_opened', $dispensedAt, 'A01')->assertOk();
        $this->postEvent($cabinet, 'umbrella_dispensed', $dispensedAt, 'A01', $umbrella->code)
            ->assertOk()
            ->assertJsonPath('result', 'applied');

        $order = Order::where('order_no', $orderNo)->firstOrFail();
        $this->assertSame('borrowed', $order->status);
        $this->assertSame($dispensedAt, $order->borrowed_at->toIso8601String());

        // 柜位与雨伞状态同步
        $this->assertSame('empty', Slot::where('label', 'A01')->firstOrFail()->state);
        $this->assertSame('borrowed', $umbrella->fresh()->status);
        $this->assertNull($umbrella->fresh()->slot_id);

        // 站点可借数减一、空位加一
        $stats = collect($this->getJson('/api/stations')->json('stations'))->firstWhere('code', 'ST-A');
        $this->assertSame(1, $stats['available_umbrellas']);
        $this->assertSame(3, $stats['empty_slots']);
    }

    public function test_borrow_rejected_when_station_has_no_borrowable_umbrella(): void
    {
        $this->makeStation('ST-A', 3, 0); // 空站

        $this->postJson('/api/orders', ['user_name' => '张三', 'station_code' => 'ST-A'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'no_umbrella');
    }

    public function test_repair_and_cleaning_umbrellas_cannot_be_borrowed(): void
    {
        ['station' => $station, 'cabinet' => $cabinet, 'umbrellas' => [$u1, $u2]] = $this->makeStation('ST-A', 2, 2);

        // 两把伞分别进入维修与清洁
        $this->postJson("/api/admin/umbrellas/{$u1->code}/action", ['action' => 'start_repair'])->assertOk();
        $this->postJson("/api/admin/umbrellas/{$u2->code}/action", ['action' => 'start_cleaning'])->assertOk();

        // 站点可借数为 0,借伞被拒绝
        $stats = collect($this->getJson('/api/stations')->json('stations'))->firstWhere('code', 'ST-A');
        $this->assertSame(0, $stats['available_umbrellas']);
        $this->assertSame(2, $stats['maintenance']);

        $this->postJson('/api/orders', ['user_name' => '张三', 'station_code' => 'ST-A'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'no_umbrella');

        // 恢复上架后可借
        $this->postJson("/api/admin/umbrellas/{$u1->code}/action", ['action' => 'restore'])->assertOk();
        $this->postJson('/api/orders', ['user_name' => '张三', 'station_code' => 'ST-A'])
            ->assertCreated()
            ->assertJsonPath('order.umbrella_code', $u1->code);
    }

    public function test_dispense_event_for_unknown_order_is_recorded_as_conflict(): void
    {
        ['cabinet' => $cabinet, 'umbrellas' => [$umbrella]] = $this->makeStation('ST-A', 2, 1);

        // 没有借伞订单却上报放伞 → 记为冲突,不改变任何状态
        $this->postEvent($cabinet, 'umbrella_dispensed', '2026-10-01T10:00:00+00:00', 'A01', $umbrella->code)
            ->assertOk()
            ->assertJsonPath('result', 'conflict');

        $this->assertSame('in_slot', $umbrella->fresh()->status);
        $this->assertSame('occupied', \App\Models\Slot::where('label', 'A01')->firstOrFail()->state);
    }
}
