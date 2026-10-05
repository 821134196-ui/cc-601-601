<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\DeviceEvent;
use App\Models\Order;
use App\Models\ReturnSession;
use App\Models\Slot;
use App\Models\Umbrella;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 设备事件入库与状态迁移。
 *
 * 页面上的一切状态(订单、柜位占用)都由这里的事件驱动:
 * - 借用成功 = umbrella_dispensed 事件(柜门实际放出雨伞)
 * - 归还成功 = umbrella_inserted + door_closed 两个事件都确认
 *
 * 幂等与核对:
 * - event_uid 唯一,重复投递(含离线补传重发)直接返回已记录结果;
 * - 每条事件按柜机 device_seq 排序后逐条应用;
 * - 与当前状态冲突的事件记录为 conflict,不产生副作用,供管理员核对。
 */
class EventIngestionService
{
    public function __construct(
        private PricingService $pricing,
        private PaymentService $payments,
    ) {}

    /**
     * 应用单条设备事件。
     *
     * @param  array{event_uid: string, cabinet_code: string, type: string,
     *               slot_label?: ?string, umbrella_code?: ?string,
     *               device_seq: int, occurred_at: string, payload?: array}  $data
     */
    public function applyOne(array $data): DeviceEvent
    {
        return DB::transaction(function () use ($data) {
            // 幂等键:同一事件重复投递(网络重试 / 离线补传重发)不再产生副作用
            $existing = DeviceEvent::where('event_uid', $data['event_uid'])->first();
            if ($existing) {
                return $existing;
            }

            $cabinet = Cabinet::where('code', $data['cabinet_code'])->first();
            if (! $cabinet) {
                throw new DomainException('cabinet_not_found', '柜机不存在: '.$data['cabinet_code'], 422);
            }

            $slot = isset($data['slot_label'])
                ? Slot::where('cabinet_id', $cabinet->id)->where('label', $data['slot_label'])->first()
                : null;
            $umbrella = isset($data['umbrella_code'])
                ? Umbrella::where('code', $data['umbrella_code'])->first()
                : null;

            $occurredAt = Carbon::parse($data['occurred_at']);

            [$result, $note] = match ($data['type']) {
                DeviceEvent::TYPE_DOOR_OPENED => $this->applyDoorOpened($slot),
                DeviceEvent::TYPE_DOOR_CLOSED => $this->applyDoorClosed($slot, $occurredAt),
                DeviceEvent::TYPE_DISPENSED => $this->applyDispensed($slot, $umbrella, $occurredAt),
                DeviceEvent::TYPE_INSERTED => $this->applyInserted($slot, $umbrella, $occurredAt),
                default => [DeviceEvent::RESULT_CONFLICT, '未知事件类型: '.$data['type']],
            };

            $cabinet->last_seen_at = now();
            $cabinet->save();

            return DeviceEvent::create([
                'event_uid' => $data['event_uid'],
                'cabinet_id' => $cabinet->id,
                'slot_id' => $slot?->id,
                'umbrella_id' => $umbrella?->id,
                'type' => $data['type'],
                'device_seq' => $data['device_seq'],
                'occurred_at' => $occurredAt,
                'received_at' => now(),
                'result' => $result,
                'note' => $note,
                'payload' => $data['payload'] ?? null,
            ]);
        });
    }

    /**
     * 批量补传(柜机离线恢复后):按柜机本地序号排序后逐条应用,
     * 每条独立事务,重复事件标记 duplicate,冲突事件标记 conflict。
     *
     * @param  array<int, array>  $events
     * @return array<int, array{event_uid: string, result: string, note: ?string}>
     */
    public function applyBatch(array $events): array
    {
        usort($events, fn ($a, $b) => [$a['cabinet_code'] ?? '', $a['device_seq'] ?? 0]
            <=> [$b['cabinet_code'] ?? '', $b['device_seq'] ?? 0]);

        $results = [];
        foreach ($events as $event) {
            try {
                $record = $this->applyOne($event);
                // 事件已存在(重复补传)→ 本次投递判重;库中保留首次处理结果
                $results[] = [
                    'event_uid' => $event['event_uid'],
                    'result' => $record->wasRecentlyCreated ? $record->result : DeviceEvent::RESULT_DUPLICATE,
                    'note' => $record->note,
                ];
            } catch (DomainException $e) {
                $results[] = [
                    'event_uid' => $event['event_uid'] ?? '?',
                    'result' => DeviceEvent::RESULT_CONFLICT,
                    'note' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /** @return array{0: string, 1: ?string} */
    private function applyDoorOpened(?Slot $slot): array
    {
        if (! $slot) {
            return [DeviceEvent::RESULT_CONFLICT, '未知柜位'];
        }

        return [DeviceEvent::RESULT_APPLIED, null];
    }

    /**
     * 借出确认:以柜门实际放出雨伞为准。
     * 订单 dispensing → borrowed,计费起点 = 事件发生时间。
     *
     * @return array{0: string, 1: ?string}
     */
    private function applyDispensed(?Slot $slot, ?Umbrella $umbrella, Carbon $occurredAt): array
    {
        if (! $umbrella) {
            return [DeviceEvent::RESULT_CONFLICT, '未知雨伞'];
        }
        if (! $slot) {
            return [DeviceEvent::RESULT_CONFLICT, '未知柜位'];
        }

        $order = Order::where('umbrella_id', $umbrella->id)
            ->where('status', Order::STATUS_DISPENSING)
            ->lockForUpdate()
            ->first();

        if (! $order) {
            // 同一把伞已在借出中 → 同一物理事件的重复上报(换了 event_uid 重发)
            $alreadyOut = Order::where('umbrella_id', $umbrella->id)
                ->where('status', Order::STATUS_BORROWED)
                ->exists();
            if ($alreadyOut && $umbrella->status === Umbrella::STATUS_BORROWED) {
                return [DeviceEvent::RESULT_DUPLICATE, '雨伞已借出,忽略重复上报'];
            }

            return [DeviceEvent::RESULT_CONFLICT, '没有等待放伞的订单'];
        }

        if ($slot->umbrella_id !== $umbrella->id || $slot->state !== Slot::STATE_OCCUPIED) {
            return [DeviceEvent::RESULT_CONFLICT, '柜位与雨伞不匹配'];
        }

        $order->status = Order::STATUS_BORROWED;
        $order->borrowed_at = $occurredAt;   // 计费起点:柜门实际放出雨伞的时间
        $order->save();

        $slot->state = Slot::STATE_EMPTY;
        $slot->umbrella_id = null;
        $slot->save();

        $umbrella->status = Umbrella::STATUS_BORROWED;
        $umbrella->slot_id = null;
        $umbrella->save();

        return [DeviceEvent::RESULT_APPLIED, null];
    }

    /**
     * 入柜确认(归还条件之一):设备 RFID 确认雨伞已入柜。
     * 仅记录 inserted_at;归还完成还需等 door_closed。
     *
     * @return array{0: string, 1: ?string}
     */
    private function applyInserted(?Slot $slot, ?Umbrella $umbrella, Carbon $occurredAt): array
    {
        if (! $umbrella) {
            return [DeviceEvent::RESULT_CONFLICT, '未知雨伞'];
        }
        if (! $slot) {
            return [DeviceEvent::RESULT_CONFLICT, '未知柜位'];
        }

        $session = ReturnSession::where('slot_id', $slot->id)
            ->where('status', ReturnSession::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if (! $session) {
            // 伞已在该柜位 → 重复归还上报,不能再占一个柜位或重复计费
            if ($umbrella->status === Umbrella::STATUS_IN_SLOT && $slot->umbrella_id === $umbrella->id) {
                return [DeviceEvent::RESULT_DUPLICATE, '雨伞已在柜,忽略重复归还'];
            }

            return [DeviceEvent::RESULT_CONFLICT, '该柜位没有进行中的还伞会话'];
        }

        $order = Order::whereKey($session->order_id)->lockForUpdate()->firstOrFail();
        if ($order->umbrella_id !== $umbrella->id) {
            return [DeviceEvent::RESULT_CONFLICT, '放入的雨伞与订单不符'];
        }

        if ($session->inserted_at !== null) {
            return [DeviceEvent::RESULT_DUPLICATE, '入柜事件已记录'];
        }

        $session->inserted_at = $occurredAt;
        $session->save();

        if ($session->door_closed_at !== null) {
            $this->finalizeReturn($session, $order, $slot, $umbrella);
        }

        return [DeviceEvent::RESULT_APPLIED, null];
    }

    /**
     * 关门确认(归还条件之二):柜门关闭。
     * 若该柜位的还伞会话已确认入柜,则归还成立。
     *
     * @return array{0: string, 1: ?string}
     */
    private function applyDoorClosed(?Slot $slot, Carbon $occurredAt): array
    {
        if (! $slot) {
            return [DeviceEvent::RESULT_CONFLICT, '未知柜位'];
        }

        $session = ReturnSession::where('slot_id', $slot->id)
            ->where('status', ReturnSession::STATUS_ACTIVE)
            ->whereNotNull('inserted_at')
            ->lockForUpdate()
            ->first();

        if (! $session) {
            // 借伞后的关门、空门关门等:仅记录,无状态迁移
            return [DeviceEvent::RESULT_APPLIED, '信息事件'];
        }

        $session->door_closed_at = $occurredAt;
        $session->save();

        $order = Order::whereKey($session->order_id)->lockForUpdate()->firstOrFail();
        $umbrella = Umbrella::whereKey($order->umbrella_id)->lockForUpdate()->firstOrFail();

        $this->finalizeReturn($session, $order, $slot, $umbrella);

        return [DeviceEvent::RESULT_APPLIED, null];
    }

    /**
     * 归还成立:入柜 + 关门两个事件都确认。
     * 计费终点取两个事件中较晚的发生时间;订单完成、柜位占用、生成支付单。
     */
    private function finalizeReturn(
        ReturnSession $session,
        Order $order,
        Slot $slot,
        Umbrella $umbrella,
    ): void {
        if ($session->status !== ReturnSession::STATUS_ACTIVE) {
            return; // 已完成/已取消 —— 重复事件不再产生副作用
        }
        if (! in_array($order->status, [Order::STATUS_RETURNING, Order::STATUS_BORROWED], true)) {
            return;
        }

        $returnedAt = $session->inserted_at->gt($session->door_closed_at)
            ? $session->inserted_at
            : $session->door_closed_at;

        $order->status = Order::STATUS_COMPLETED;
        $order->returned_at = $returnedAt;
        $order->return_station_id = $session->station_id;
        $order->fee_cents = $this->pricing->feeCents($order->borrowed_at, $returnedAt);
        $order->save();

        $umbrella->status = Umbrella::STATUS_IN_SLOT;
        $umbrella->slot_id = $slot->id;
        $umbrella->save();

        $slot->state = Slot::STATE_OCCUPIED;
        $slot->umbrella_id = $umbrella->id;
        $slot->reserved_by_return_id = null;
        $slot->save();

        $session->status = ReturnSession::STATUS_COMPLETED;
        $session->save();

        // 一单一付:firstOrCreate 保证重复流程也不会生成第二张支付单
        $this->payments->createForOrder($order);
    }
}
