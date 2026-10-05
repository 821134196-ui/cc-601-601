<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\Umbrella;
use Tests\UmbrellaFlowTestCase;

/**
 * 雨伞处置与全局一致性：维修/清洁中的伞不可借；任意操作序列后数量始终一致。
 */
class RepairAndConsistencyTest extends UmbrellaFlowTestCase
{
    public function test_repairing_umbrella_cannot_be_borrowed(): void
    {
        // 老街口站 2 把伞全部标记维修
        foreach (Umbrella::where('station_id', 3)->get() as $u) {
            $this->patchJson("/api/admin/umbrellas/{$u->id}", ['status' => 'repair'])->assertOk();
        }

        $this->assertSame(0, Station::find(3)->availableCount());
        $this->postJson('/api/orders', ['station_id' => 3, 'user_name' => '测试'])
            ->assertStatus(422);

        // 恢复一把后可借
        $u = Umbrella::where('station_id', 3)->first();
        $this->patchJson("/api/admin/umbrellas/{$u->id}", ['status' => 'in_slot'])->assertOk();
        $this->assertSame(1, Station::find(3)->availableCount());
        $this->assertGlobalConsistency();
    }

    public function test_borrow_skips_cleaning_and_repair_umbrellas(): void
    {
        // 阳光社区站 3 把伞：1 清洁、1 维修，只剩 1 可借
        [$u1, $u2, $u3] = Umbrella::where('station_id', 1)->orderBy('id')->get();
        $this->patchJson("/api/admin/umbrellas/{$u1->id}", ['status' => 'cleaning'])->assertOk();
        $this->patchJson("/api/admin/umbrellas/{$u2->id}", ['status' => 'repair'])->assertOk();

        $this->assertSame(1, Station::find(1)->availableCount());

        $order = $this->borrowAndTake(1);
        $this->assertSame($u3->id, $order->umbrella_id, '只能借到状态可用的那把伞');

        // 可借数归零后再借失败
        $this->postJson('/api/orders', ['station_id' => 1, 'user_name' => '测试'])
            ->assertStatus(422);
        $this->assertGlobalConsistency();
    }

    public function test_lost_umbrella_frees_its_slot(): void
    {
        $u = Umbrella::where('station_id', 3)->first();
        $slotId = $u->slot_id;

        $this->patchJson("/api/admin/umbrellas/{$u->id}", ['status' => 'lost'])->assertOk();

        $this->assertSame(1, Station::find(3)->emptySlotCount(), '丢失的伞释放其柜位');
        $this->assertNull($u->fresh()->slot_id);
        $this->assertNull(\App\Models\Slot::find($slotId)->umbrella_id);
        $this->assertGlobalConsistency();
    }

    public function test_mixed_operations_keep_counts_consistent(): void
    {
        // 混合操作序列：借、还、换站、取消、维修、恢复
        $o1 = $this->borrowAndTake(1, '用户甲');
        $o2 = $this->borrowAndTake(1, '用户乙');
        $this->assertGlobalConsistency();

        $this->initiateReturn($o1, 3)->assertStatus(409); // 满柜
        $this->initiateReturn($o1, 2)->assertOk();
        $this->placeIntoCabinet(2, $o1);
        $this->assertGlobalConsistency();

        $this->initiateReturn($o2, 2)->assertOk();
        $this->postJson("/api/orders/{$o2->id}/cancel-return")->assertOk();
        $this->initiateReturn($o2, 1)->assertOk();
        $this->placeIntoCabinet(1, $o2);
        $this->assertGlobalConsistency();

        $u = Umbrella::where('station_id', 2)->where('status', 'in_slot')->first();
        $this->patchJson("/api/admin/umbrellas/{$u->id}", ['status' => 'repair']);
        $this->patchJson("/api/admin/umbrellas/{$u->id}", ['status' => 'in_slot']);
        $this->assertGlobalConsistency();

        // 全部订单完成、柜位与雨伞一一对应
        $this->assertSame(0, \App\Models\Order::whereIn('status', ['dispensing', 'active', 'returning'])->count());
    }
}
