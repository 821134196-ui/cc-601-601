<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Slot;
use App\Models\Umbrella;
use Tests\TestCase;

class ReturnFlowTest extends TestCase
{
    public function test_return_completes_only_after_insert_and_door_closed(): void
    {
        ['station' => $stationA, 'cabinet' => $cabinetA] = $this->makeStation('ST-A', 3, 2);
        ['station' => $stationB, 'cabinet' => $cabinetB] = $this->makeStation('ST-B', 3, 0);

        ['order_no' => $orderNo, 'umbrella_code' => $umbrellaCode] =
            $this->borrowUmbrella($stationA, $cabinetA, borrowedAt: '2026-10-01T10:00:00+00:00');

        // 跨站归还:在 B 站创建还伞会话
        $session = $this->postJson('/api/returns', ['order_no' => $orderNo, 'station_code' => 'ST-B'])
            ->assertCreated();
        $slotLabel = $session->json('slot_label');

        // 只入柜、未关门 → 归还不成立
        $this->postEvent($cabinetB, 'door_opened', '2026-10-01T10:40:00+00:00', $slotLabel)->assertOk();
        $this->postEvent($cabinetB, 'umbrella_inserted', '2026-10-01T10:40:05+00:00', $slotLabel, $umbrellaCode)
            ->assertOk()->assertJsonPath('result', 'applied');

        $order = Order::where('order_no', $orderNo)->firstOrFail();
        $this->assertSame('returning', $order->status);
        $this->assertNull($order->returned_at);

        // 柜门关闭 → 两个条件齐备,归还成立
        $this->postEvent($cabinetB, 'door_closed', '2026-10-01T10:40:09+00:00', $slotLabel)
            ->assertOk()->assertJsonPath('result', 'applied');

        $order->refresh();
        $this->assertSame('completed', $order->status);
        // 计费终点 = 入柜与关门两事件中较晚者
        $this->assertSame('2026-10-01T10:40:09+00:00', $order->returned_at->toIso8601String());
        // 40 分钟 → 2 个计费单元 × 50 分
        $this->assertSame(100, $order->fee_cents);
        $this->assertSame($stationB->id, $order->return_station_id);

        // 雨伞入架、柜位占用、支付单生成
        $umbrella = Umbrella::where('code', $umbrellaCode)->firstOrFail();
        $this->assertSame('in_slot', $umbrella->status);
        $slot = Slot::where('cabinet_id', $cabinetB->id)->where('label', $slotLabel)->firstOrFail();
        $this->assertSame('occupied', $slot->state);
        $this->assertSame($umbrella->id, $slot->umbrella_id);
        $this->assertNull($slot->reserved_by_return_id);

        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(100, $payment->amount_cents);
        $this->assertSame('pending', $payment->status);
    }

    public function test_full_station_rejects_with_alternatives_and_switch_keeps_billing_start(): void
    {
        ['station' => $stationA, 'cabinet' => $cabinetA] = $this->makeStation('ST-A', 3, 2);
        ['station' => $stationB, 'cabinet' => $cabinetB] = $this->makeStation('ST-B', 2, 2); // B 满柜
        ['station' => $stationC, 'cabinet' => $cabinetC] = $this->makeStation('ST-C', 4, 1);

        $borrowedAt = '2026-10-01T09:00:00+00:00';
        ['order_no' => $orderNo, 'umbrella_code' => $umbrellaCode] =
            $this->borrowUmbrella($stationA, $cabinetA, borrowedAt: $borrowedAt);

        // B 站满柜 → 409 + 备选站点
        $response = $this->postJson('/api/returns', ['order_no' => $orderNo, 'station_code' => 'ST-B']);
        $response->assertStatus(409)->assertJsonPath('error', 'station_full');
        $alternatives = collect($response->json('alternatives'));
        $this->assertTrue($alternatives->contains('code', 'ST-C'));
        $this->assertFalse($alternatives->contains('code', 'ST-B'));

        // 换到 C 站归还
        $session = $this->postJson('/api/returns', ['order_no' => $orderNo, 'station_code' => 'ST-C'])
            ->assertCreated();
        $slotLabel = $session->json('slot_label');

        $returnedAt = '2026-10-01T09:31:00+00:00';
        $this->postEvent($cabinetC, 'umbrella_inserted', $returnedAt, $slotLabel, $umbrellaCode)->assertOk();
        $this->postEvent($cabinetC, 'door_closed', $returnedAt, $slotLabel)->assertOk();

        $order = Order::where('order_no', $orderNo)->firstOrFail();
        $this->assertSame('completed', $order->status);
        // 原借用计费起点继续保留,不因换站而改变
        $this->assertSame($borrowedAt, $order->borrowed_at->toIso8601String());
        $this->assertSame(100, $order->fee_cents); // 31 分钟 → 2 单元
        $this->assertSame($stationC->id, $order->return_station_id);

        // B 站柜位未被占用,C 站空位减一
        $stats = collect($this->getJson('/api/stations')->json('stations'));
        $this->assertSame(0, $stats->firstWhere('code', 'ST-B')['empty_slots']);
        $this->assertSame(2, $stats->firstWhere('code', 'ST-C')['empty_slots']); // 4 - 1(原有伞) - 1(新还)

        $this->assertTrue($this->getJson('/api/admin/reconcile')->json('consistent'));
    }

    public function test_duplicate_return_request_reuses_session_without_reserving_another_slot(): void
    {
        ['station' => $stationA, 'cabinet' => $cabinetA] = $this->makeStation('ST-A', 3, 1);
        ['station' => $stationB] = $this->makeStation('ST-B', 2, 0);

        ['order_no' => $orderNo] = $this->borrowUmbrella($stationA, $cabinetA);

        $first = $this->postJson('/api/returns', ['order_no' => $orderNo, 'station_code' => 'ST-B'])->assertCreated();
        $second = $this->postJson('/api/returns', ['order_no' => $orderNo, 'station_code' => 'ST-B'])->assertOk();

        // 同站重复请求 → 复用同一会话,不会多预留柜位
        $this->assertSame($first->json('session.session_no'), $second->json('session.session_no'));
        $this->assertTrue($second->json('reused'));

        $stats = collect($this->getJson('/api/stations')->json('stations'))->firstWhere('code', 'ST-B');
        $this->assertSame(1, $stats['reserved_slots']);
        $this->assertSame(1, $stats['empty_slots']); // 2 个空位只被预留 1 个
    }
}
