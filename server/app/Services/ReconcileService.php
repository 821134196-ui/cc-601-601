<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\Order;
use App\Models\ReturnSession;
use App\Models\Slot;
use App\Models\Umbrella;

/**
 * 对账:核对柜位占用、雨伞位置、订单状态三者是否一致。
 * 柜机离线补传后,管理员可用此接口核对事件应用结果。
 */
class ReconcileService
{
    /**
     * @return array{consistent: bool, cabinets: array, anomalies: array<int, string>}
     */
    public function report(): array
    {
        $anomalies = [];
        $cabinets = [];

        foreach (Cabinet::with('station')->get() as $cabinet) {
            $slots = Slot::where('cabinet_id', $cabinet->id)->get();
            $slotIds = $slots->pluck('id');

            $umbrellasHere = Umbrella::whereIn('slot_id', $slotIds)->get();

            $occupied = $slots->where('state', Slot::STATE_OCCUPIED);
            $reserved = $slots->whereNotNull('reserved_by_return_id');

            // 1) 柜位 ↔ 雨伞镜像一致
            foreach ($occupied as $slot) {
                if (! $slot->umbrella_id) {
                    $anomalies[] = "柜位 {$cabinet->code}/{$slot->label} 标记占用但没有雨伞";
                    continue;
                }
                $umbrella = Umbrella::find($slot->umbrella_id);
                if (! $umbrella) {
                    $anomalies[] = "柜位 {$cabinet->code}/{$slot->label} 指向不存在的雨伞 #{$slot->umbrella_id}";
                } elseif ($umbrella->slot_id !== $slot->id) {
                    $anomalies[] = "雨伞 {$umbrella->code} 与柜位 {$cabinet->code}/{$slot->label} 指向不一致";
                } elseif ($umbrella->status === Umbrella::STATUS_BORROWED) {
                    $anomalies[] = "雨伞 {$umbrella->code} 已借出却仍占用柜位 {$cabinet->code}/{$slot->label}";
                }
            }
            foreach ($umbrellasHere as $umbrella) {
                $slot = $slots->firstWhere('id', $umbrella->slot_id);
                if (! $slot || $slot->umbrella_id !== $umbrella->id || $slot->state !== Slot::STATE_OCCUPIED) {
                    $anomalies[] = "雨伞 {$umbrella->code} 登记在柜位但柜位未登记该伞";
                }
            }

            // 2) 在架状态必须有柜位,借出状态必须无柜位
            foreach (Umbrella::all() as $umbrella) {
                if ($umbrella->status === Umbrella::STATUS_IN_SLOT && ! $umbrella->slot_id) {
                    $anomalies[] = "雨伞 {$umbrella->code} 状态在架但没有所在柜位";
                }
                if ($umbrella->status === Umbrella::STATUS_BORROWED && $umbrella->slot_id) {
                    $anomalies[] = "雨伞 {$umbrella->code} 已借出却仍登记柜位";
                }
            }

            // 3) 借出中的订单 ↔ 雨伞状态
            $activeOrders = Order::whereIn('status', [Order::STATUS_BORROWED, Order::STATUS_RETURNING])->get();
            foreach ($activeOrders as $order) {
                $umbrella = Umbrella::find($order->umbrella_id);
                if (! $umbrella || $umbrella->status !== Umbrella::STATUS_BORROWED) {
                    $anomalies[] = "订单 {$order->order_no} 进行中但雨伞状态异常";
                }
            }

            // 4) 进行中的还伞会话 ↔ 柜位预留
            $activeSessions = ReturnSession::where('status', ReturnSession::STATUS_ACTIVE)->get();
            foreach ($activeSessions as $session) {
                $slot = Slot::find($session->slot_id);
                if (! $slot || (int) $slot->reserved_by_return_id !== (int) $session->id) {
                    $anomalies[] = "还伞会话 {$session->session_no} 进行中但柜位未预留";
                }
            }
            foreach ($reserved as $slot) {
                if (! ReturnSession::whereKey($slot->reserved_by_return_id)->where('status', ReturnSession::STATUS_ACTIVE)->exists()) {
                    $anomalies[] = "柜位 {$cabinet->code}/{$slot->label} 被已结束的会话预留";
                }
            }

            $cabinets[] = [
                'cabinet_code' => $cabinet->code,
                'station' => $cabinet->station->name,
                'online' => $cabinet->online,
                'total_slots' => $slots->count(),
                'occupied_slots' => $occupied->count(),
                'empty_slots' => $slots->where('state', Slot::STATE_EMPTY)->whereNull('reserved_by_return_id')->count(),
                'reserved_slots' => $reserved->count(),
                'umbrellas_in_slot' => $umbrellasHere->where('status', Umbrella::STATUS_IN_SLOT)->count(),
                'maintenance' => $umbrellasHere->whereIn('status', [Umbrella::STATUS_CLEANING, Umbrella::STATUS_REPAIR])->count(),
            ];
        }

        return [
            'consistent' => count($anomalies) === 0,
            'cabinets' => $cabinets,
            'anomalies' => array_values(array_unique($anomalies)),
        ];
    }
}
