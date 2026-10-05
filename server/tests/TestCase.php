<?php

namespace Tests;

use App\Models\Cabinet;
use App\Models\Slot;
use App\Models\Station;
use App\Models\Umbrella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected int $eventSeq = 0;

    /**
     * 建一个站点(含柜机与柜位),并可放入若干在架雨伞。
     *
     * @return array{station: Station, cabinet: Cabinet, slots: Slot[], umbrellas: Umbrella[]}
     */
    protected function makeStation(string $code, int $slotCount, int $umbrellaCount = 0): array
    {
        $station = Station::create(['code' => $code, 'name' => $code.'站', 'address' => '']);
        $cabinet = Cabinet::create(['station_id' => $station->id, 'code' => 'CAB-'.$code, 'online' => true]);

        $slots = [];
        $umbrellas = [];
        for ($i = 1; $i <= $slotCount; $i++) {
            $slot = Slot::create([
                'cabinet_id' => $cabinet->id,
                'label' => 'A'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'state' => Slot::STATE_EMPTY,
            ]);
            $slots[] = $slot;

            if ($i <= $umbrellaCount) {
                $umbrella = Umbrella::create([
                    'code' => 'UMB-'.$code.'-'.$i,
                    'status' => Umbrella::STATUS_IN_SLOT,
                    'slot_id' => $slot->id,
                ]);
                $slot->update(['state' => Slot::STATE_OCCUPIED, 'umbrella_id' => $umbrella->id]);
                $umbrellas[] = $umbrella;
            }
        }

        return ['station' => $station, 'cabinet' => $cabinet, 'slots' => $slots, 'umbrellas' => $umbrellas];
    }

    /** 构造并上报一条设备事件 */
    protected function postEvent(
        Cabinet $cabinet,
        string $type,
        string $occurredAt,
        ?string $slotLabel = null,
        ?string $umbrellaCode = null,
        ?string $eventUid = null,
    ): \Illuminate\Testing\TestResponse {
        $this->eventSeq++;

        return $this->postJson('/api/device/events', [
            'event_uid' => $eventUid ?? 'E-'.Str::uuid()->toString(),
            'cabinet_code' => $cabinet->code,
            'type' => $type,
            'slot_label' => $slotLabel,
            'umbrella_code' => $umbrellaCode,
            'device_seq' => $this->eventSeq,
            'occurred_at' => $occurredAt,
        ]);
    }

    /** 走通借伞:下单 → 柜门放伞事件 → 订单进入借出中 */
    protected function borrowUmbrella(Station $station, Cabinet $cabinet, string $user = '测试用户', string $borrowedAt = '2026-10-01T10:00:00+00:00'): array
    {
        $response = $this->postJson('/api/orders', [
            'user_name' => $user,
            'station_code' => $station->code,
        ])->assertCreated();

        $orderNo = $response->json('order.order_no');
        $umbrellaCode = $response->json('order.umbrella_code');
        $slotLabel = $response->json('slot_label');

        $this->postEvent($cabinet, 'door_opened', $borrowedAt, $slotLabel)->assertOk();
        $this->postEvent($cabinet, 'umbrella_dispensed', $borrowedAt, $slotLabel, $umbrellaCode)->assertOk();

        return ['order_no' => $orderNo, 'umbrella_code' => $umbrellaCode, 'slot_label' => $slotLabel];
    }

    /** 走通还伞:创建会话 → 入柜 + 关门事件 */
    protected function returnUmbrella(
        string $orderNo,
        Station $station,
        Cabinet $cabinet,
        string $umbrellaCode,
        string $at = '2026-10-01T11:00:00+00:00',
    ): \Illuminate\Testing\TestResponse {
        $response = $this->postJson('/api/returns', [
            'order_no' => $orderNo,
            'station_code' => $station->code,
        ]);
        $response->assertCreated();
        $slotLabel = $response->json('slot_label');

        $this->postEvent($cabinet, 'door_opened', $at, $slotLabel)->assertOk();
        $this->postEvent($cabinet, 'umbrella_inserted', $at, $slotLabel, $umbrellaCode)->assertOk();
        $this->postEvent($cabinet, 'door_closed', $at, $slotLabel)->assertOk();

        return $response;
    }
}
