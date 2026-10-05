<?php

namespace App\Services;

use App\Models\DeviceEvent;
use App\Models\DoorCommand;
use App\Models\SimOutbox;
use App\Models\Station;
use Illuminate\Support\Str;

/**
 * 柜机本地模拟器。
 *
 * 模拟真实柜机的两个行为特征：
 *  1. 在线时事件立即上报；离线时事件先存入柜机本地队列（sim_outbox），恢复在线后批量补传；
 *  2. 每个事件带全局唯一 event_uid，服务端据此幂等去重——补传、重传都不会重复生效。
 */
class SimCabinet
{
    public function __construct(private EventProcessor $processor) {}

    public function setOnline(Station $station, bool $online): Station
    {
        $station->update(['cabinet_online' => $online]);

        return $station;
    }

    /** 模拟用户从打开的柜门取走雨伞 → 产生 umbrella_dispensed 事件 */
    public function takeUmbrella(Station $station, int $slotId): array
    {
        $command = DoorCommand::where('station_id', $station->id)->where('slot_id', $slotId)
            ->where('purpose', 'borrow')->where('status', 'pending')->firstOrFail();

        return $this->emit($station, DeviceEvent::TYPE_DISPENSED, [
            'slot_id' => $slotId,
            'order_id' => $command->order_id,
            'umbrella_id' => $command->order->umbrella_id,
        ]);
    }

    /** 模拟用户放入雨伞并关闭柜门（设备确认入柜）→ 产生 umbrella_returned 事件 */
    public function placeUmbrella(Station $station, int $slotId): array
    {
        $command = DoorCommand::where('station_id', $station->id)->where('slot_id', $slotId)
            ->where('purpose', 'return')->where('status', 'pending')->firstOrFail();

        return $this->emit($station, DeviceEvent::TYPE_RETURNED, [
            'slot_id' => $slotId,
            'order_id' => $command->order_id,
            'umbrella_id' => $command->order->umbrella_id,
        ]);
    }

    /** 产生事件：在线即时报送，离线入柜机本地队列 */
    private function emit(Station $station, string $type, array $payload): array
    {
        $eventUid = 'EVT-'.$station->code.'-'.strtoupper(Str::uuid()->toString());
        $occurredAt = now()->toIso8601String();

        if ($station->cabinet_online) {
            $result = $this->processor->process($eventUid, $type, $station->id, $payload, $occurredAt);

            return ['delivery' => 'sent', 'event_uid' => $eventUid, 'result' => $result['event']->result];
        }

        SimOutbox::create([
            'station_id' => $station->id,
            'event_uid' => $eventUid,
            'type' => $type,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
        ]);

        return ['delivery' => 'queued', 'event_uid' => $eventUid, 'result' => null];
    }

    /** 恢复在线：把柜机本地队列中的事件按序补传，并回报每条的处理结果供核对 */
    public function sync(Station $station): array
    {
        $queued = SimOutbox::where('station_id', $station->id)->whereNull('sent_at')
            ->orderBy('occurred_at')->get();

        $events = $queued->map(fn ($e) => [
            'event_uid' => $e->event_uid,
            'type' => $e->type,
            'station_id' => $station->id,
            'payload' => $e->payload,
            'occurred_at' => $e->occurred_at->toIso8601String(),
        ])->all();

        $results = $this->processor->processBatch($events);

        SimOutbox::whereIn('id', $queued->pluck('id'))->update(['sent_at' => now()]);

        return $results;
    }
}
