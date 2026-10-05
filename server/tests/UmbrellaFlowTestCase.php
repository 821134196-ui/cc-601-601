<?php

namespace Tests;

use App\Models\DoorCommand;
use App\Models\Order;
use App\Models\Slot;
use App\Models\Station;
use App\Models\Umbrella;
use Database\Seeders\UmbrellaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 借还流程公共动作：通过 HTTP 接口走完整链路（与真实使用一致）。
 */
abstract class UmbrellaFlowTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UmbrellaSeeder::class);
    }

    /** 发起借伞并模拟柜机放伞，返回已生效（active）的订单 */
    protected function borrowAndTake(int $stationId, string $user = '测试用户'): Order
    {
        $order = $this->borrowOnly($stationId, $user);
        $this->takeFromCabinet($stationId, $order);

        return $order->fresh();
    }

    /** 仅发起借伞（订单处于待取伞状态） */
    protected function borrowOnly(int $stationId, string $user = '测试用户'): Order
    {
        $res = $this->postJson('/api/orders', ['station_id' => $stationId, 'user_name' => $user]);
        $res->assertCreated();

        return Order::findOrFail($res->json('id'));
    }

    /** 模拟柜机：用户从打开的柜门取走雨伞 */
    protected function takeFromCabinet(int $stationId, Order $order): void
    {
        $command = DoorCommand::where('order_id', $order->id)->where('purpose', 'borrow')->firstOrFail();
        $this->postJson("/api/cabinet/{$stationId}/take", ['slot_id' => $command->slot_id])
            ->assertOk();
    }

    /** 发起还伞（指定站点），返回响应 */
    protected function initiateReturn(Order $order, int $stationId)
    {
        return $this->postJson("/api/orders/{$order->id}/return", ['station_id' => $stationId]);
    }

    /** 模拟柜机：用户放伞并关门，设备确认入柜 */
    protected function placeIntoCabinet(int $stationId, Order $order): void
    {
        $command = DoorCommand::where('order_id', $order->id)->where('purpose', 'return')
            ->where('status', 'pending')->firstOrFail();
        $this->postJson("/api/cabinet/{$stationId}/place", ['slot_id' => $command->slot_id])
            ->assertOk();
    }

    /** 一致性不变量：每个站点的可借数/空位数必须等于柜位与雨伞的实际物理状态 */
    protected function assertGlobalConsistency(): void
    {
        foreach (Station::all() as $station) {
            $slots = Slot::where('station_id', $station->id)->get();

            // 占用柜位必有一把物理在柜的伞（可借/清洁/维修），且伞的位置指回该柜位
            foreach ($slots->where('status', 'occupied') as $slot) {
                $umbrella = Umbrella::find($slot->umbrella_id);
                $this->assertNotNull($umbrella, "柜位 {$slot->label} 占用但无伞");
                $this->assertContains($umbrella->status, ['in_slot', 'cleaning', 'repair']);
                $this->assertSame($slot->id, $umbrella->slot_id);
                $this->assertSame($station->id, $umbrella->station_id);
            }
            // 空柜位/预留柜位不应挂伞
            foreach ($slots->whereIn('status', ['empty', 'reserved']) as $slot) {
                if ($slot->status === 'empty') {
                    $this->assertNull($slot->umbrella_id, "空柜位 {$slot->label} 不应挂伞");
                }
            }

            // 页面展示数量与物理状态一致
            $this->assertSame(
                Umbrella::where('station_id', $station->id)->where('status', 'in_slot')->count(),
                $station->availableCount(),
                "站点 {$station->code} 可借数不一致"
            );
            $this->assertSame(
                $slots->where('status', 'empty')->count(),
                $station->emptySlotCount(),
                "站点 {$station->code} 空位数不一致"
            );
        }

        // 每把伞恰好处于一个位置：柜内某柜位 / 用户持有 / 丢失
        foreach (Umbrella::all() as $umbrella) {
            $slotRefs = Slot::where('umbrella_id', $umbrella->id)->count();
            if ($umbrella->status === 'in_slot' || in_array($umbrella->status, ['cleaning', 'repair'])) {
                $this->assertSame(1, $slotRefs, "伞 {$umbrella->code} 在柜但柜位引用数异常");
            } else {
                $this->assertSame(0, $slotRefs, "伞 {$umbrella->code} 离柜但仍挂在柜位上");
            }
        }
    }
}
