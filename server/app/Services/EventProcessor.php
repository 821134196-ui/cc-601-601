<?php

namespace App\Services;

use App\Models\DeviceEvent;
use App\Models\DoorCommand;
use App\Models\Order;
use App\Models\Slot;
use App\Models\Umbrella;
use Illuminate\Support\Facades\DB;

/**
 * 柜机事件处理器。
 *
 * 一致性核心：
 *  - 每个柜机事件带全局唯一 event_uid，重复上传（网络重试 / 离线补传重发）只记录为 duplicate，不改变任何状态；
 *  - 事件落库与状态迁移在同一个事务内完成；
 *  - 状态迁移带前置校验（订单必须处于期望状态、柜位必须处于期望状态），
 *    即使柜机故障用新 event_uid 重发物理上同一动作，也不会重复释放/占用柜位或重复计费。
 */
class EventProcessor
{
    public function __construct(private PricingService $pricing) {}

    /**
     * 处理单个事件，返回 ['event' => DeviceEvent, 'duplicate' => bool]
     */
    public function process(string $eventUid, string $type, int $stationId, array $payload, string $occurredAt): array
    {
        return DB::transaction(function () use ($eventUid, $type, $stationId, $payload, $occurredAt) {
            // 幂等键：同一 event_uid 已处理过 → 直接返回，状态不变
            $existing = DeviceEvent::where('event_uid', $eventUid)->first();
            if ($existing) {
                return ['event' => $existing, 'duplicate' => true];
            }

            $event = DeviceEvent::create([
                'event_uid' => $eventUid,
                'station_id' => $stationId,
                'slot_id' => $payload['slot_id'] ?? null,
                'umbrella_id' => $payload['umbrella_id'] ?? null,
                'order_id' => $payload['order_id'] ?? null,
                'type' => $type,
                'payload' => $payload,
                'occurred_at' => $occurredAt,
                'processed_at' => now(),
                'result' => 'processed',
            ]);

            try {
                match ($type) {
                    DeviceEvent::TYPE_DISPENSED => $this->applyDispensed($event, $payload),
                    DeviceEvent::TYPE_RETURNED => $this->applyReturned($event, $payload),
                    default => throw new \RuntimeException("未知事件类型: {$type}"),
                };
            } catch (StateMismatchException $e) {
                // 事件合法但状态对不上（如柜机故障重发）：记录为 rejected，不改变业务状态
                $event->update(['result' => 'rejected:'.$e->getMessage()]);
            }

            return ['event' => $event->fresh(), 'duplicate' => false];
        });
    }

    /**
     * 离线补传：批量处理，按发生时间排序，逐条幂等处理。
     * 返回每条事件的处理结果，便于柜机端核对事件记录。
     */
    public function processBatch(array $events): array
    {
        usort($events, fn ($a, $b) => strcmp($a['occurred_at'], $b['occurred_at']));

        $results = [];
        foreach ($events as $e) {
            $r = $this->process($e['event_uid'], $e['type'], $e['station_id'], $e['payload'] ?? [], $e['occurred_at']);
            $results[] = [
                'event_uid' => $e['event_uid'],
                'duplicate' => $r['duplicate'],
                'result' => $r['event']->result,
            ];
        }

        return $results;
    }

    /** 柜门实际放出雨伞 → 借用成功，计费起点 = 事件发生时间 */
    private function applyDispensed(DeviceEvent $event, array $payload): void
    {
        $order = Order::whereKey($payload['order_id'] ?? null)->lockForUpdate()->first();
        if (! $order || $order->status !== Order::STATUS_DISPENSING) {
            throw new StateMismatchException('订单不在待取伞状态');
        }

        $slot = Slot::whereKey($payload['slot_id'] ?? null)->lockForUpdate()->first();
        $umbrella = Umbrella::whereKey($order->umbrella_id)->lockForUpdate()->first();
        if (! $slot || $slot->status !== 'reserved' || (int) $slot->umbrella_id !== (int) $umbrella?->id) {
            throw new StateMismatchException('柜位不是本订单预留状态');
        }

        // 订单生效：计费起点以柜机事件时间为准
        $order->update([
            'status' => Order::STATUS_ACTIVE,
            'borrowed_at' => $event->occurred_at,
        ]);

        // 柜位释放、雨伞离柜
        $slot->update(['status' => 'empty', 'umbrella_id' => null]);
        $umbrella->update(['status' => Umbrella::STATUS_BORROWED, 'slot_id' => null, 'station_id' => null]);

        DoorCommand::where('order_id', $order->id)->where('purpose', 'borrow')
            ->where('status', 'pending')->update(['status' => 'done']);
    }

    /** 柜门关闭且设备确认雨伞入柜 → 归还成功，计费终点 = 事件发生时间 */
    private function applyReturned(DeviceEvent $event, array $payload): void
    {
        $order = Order::whereKey($payload['order_id'] ?? null)->lockForUpdate()->first();
        if (! $order || $order->status !== Order::STATUS_RETURNING) {
            throw new StateMismatchException('订单不在待归还状态');
        }

        $slot = Slot::whereKey($payload['slot_id'] ?? null)->lockForUpdate()->first();
        $umbrella = Umbrella::whereKey($order->umbrella_id)->lockForUpdate()->first();
        if (! $slot || $slot->status !== 'reserved' || $slot->umbrella_id !== null) {
            throw new StateMismatchException('柜位不是本订单预留的空位');
        }

        // 订单完成：费用在归还确认时一次性核定，此后不再变化
        $fee = $this->pricing->feeFor($order->borrowed_at, $event->occurred_at);
        $order->update([
            'status' => Order::STATUS_COMPLETED,
            'return_station_id' => $event->station_id,
            'returned_at' => $event->occurred_at,
            'fee_cents' => $fee,
        ]);

        // 柜位占用、雨伞入柜
        $slot->update(['status' => 'occupied', 'umbrella_id' => $umbrella->id]);
        $umbrella->update([
            'status' => Umbrella::STATUS_IN_SLOT,
            'slot_id' => $slot->id,
            'station_id' => $slot->station_id,
        ]);

        DoorCommand::where('order_id', $order->id)->where('purpose', 'return')
            ->where('status', 'pending')->update(['status' => 'done']);
    }
}
