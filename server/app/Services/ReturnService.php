<?php

namespace App\Services;

use App\Models\CabinetCommand;
use App\Models\Order;
use App\Models\ReturnSession;
use App\Models\Slot;
use App\Models\Station;
use Illuminate\Support\Facades\DB;

class ReturnService
{
    public function __construct(private OrderService $orders) {}

    /**
     * 发起还伞:在目标站预留一个空柜位并下发开门命令。
     *
     * - 幂等:同一订单对同一站点重复请求,返回已有会话,不会多占柜位。
     * - 满柜:抛出 station_full 并附带其他有空位的站点,用户换站后
     *   订单的 borrowed_at(计费起点)不变。
     *
     * @return array{session: ReturnSession, slot: Slot, reused: bool}
     */
    public function createReturnSession(string $orderNo, string $stationCode): array
    {
        return DB::transaction(function () use ($orderNo, $stationCode) {
            $order = Order::where('order_no', $orderNo)->lockForUpdate()->first();
            if (! $order) {
                throw new DomainException('order_not_found', '订单不存在', 404);
            }
            if (! in_array($order->status, [Order::STATUS_BORROWED, Order::STATUS_RETURNING], true)) {
                throw new DomainException('order_not_returnable', '当前订单状态不可还伞', 409);
            }

            $station = Station::where('code', $stationCode)->with('cabinet')->first();
            if (! $station || ! $station->cabinet) {
                throw new DomainException('station_not_found', '站点不存在', 404);
            }

            // 已有进行中的会话:同站直接复用(幂等),换站则先取消旧会话释放柜位
            $existing = ReturnSession::where('order_id', $order->id)
                ->where('status', ReturnSession::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ($existing->station_id === $station->id) {
                    return ['session' => $existing, 'slot' => $existing->slot, 'reused' => true];
                }
                $this->releaseSession($existing, ReturnSession::STATUS_CANCELLED);
            }

            $slot = Slot::where('cabinet_id', $station->cabinet->id)
                ->where('state', Slot::STATE_EMPTY)
                ->whereNull('reserved_by_return_id')
                ->orderBy('label')
                ->lockForUpdate()
                ->first();

            if (! $slot) {
                throw new DomainException(
                    'station_full',
                    '该站柜位已满,请选择其他站点归还',
                    409,
                    ['alternatives' => $this->alternatives($station->id)]
                );
            }

            $session = ReturnSession::create([
                'session_no' => $this->orders->generateNo('R'),
                'order_id' => $order->id,
                'station_id' => $station->id,
                'slot_id' => $slot->id,
                'status' => ReturnSession::STATUS_ACTIVE,
                'expires_at' => now()->addSeconds((int) config('umbrella.return_session_ttl_seconds', 600)),
            ]);

            $slot->reserved_by_return_id = $session->id;
            $slot->save();

            $order->status = Order::STATUS_RETURNING;
            $order->save();

            CabinetCommand::create([
                'cabinet_id' => $station->cabinet->id,
                'slot_id' => $slot->id,
                'type' => CabinetCommand::TYPE_OPEN_DOOR,
                'status' => CabinetCommand::STATUS_PENDING,
                'ref_type' => 'return_session',
                'ref_id' => $session->id,
                'payload' => ['umbrella_code' => $order->umbrella->code, 'slot_label' => $slot->label],
                'expires_at' => now()->addSeconds((int) config('umbrella.return_session_ttl_seconds', 600)),
            ]);

            return ['session' => $session->fresh(), 'slot' => $slot->fresh(), 'reused' => false];
        });
    }

    /**
     * 释放会话:取消/过期时归还柜位预留,订单回到借出状态。
     */
    public function releaseSession(ReturnSession $session, string $toStatus): void
    {
        if ($session->slot_id) {
            Slot::whereKey($session->slot_id)
                ->where('reserved_by_return_id', $session->id)
                ->update(['reserved_by_return_id' => null]);
        }
        $session->status = $toStatus;
        $session->save();

        $order = $session->order;
        if ($order && $order->status === Order::STATUS_RETURNING) {
            $order->status = Order::STATUS_BORROWED;
            $order->save();
        }
    }

    /**
     * 其他有空位的站点(满柜换站建议)。
     *
     * @return array<int, array{code: string, name: string, empty_slots: int}>
     */
    public function alternatives(int $excludeStationId): array
    {
        return Station::where('stations.id', '!=', $excludeStationId)
            ->join('cabinets', 'cabinets.station_id', '=', 'stations.id')
            ->join('slots', 'slots.cabinet_id', '=', 'cabinets.id')
            ->where('slots.state', Slot::STATE_EMPTY)
            ->whereNull('slots.reserved_by_return_id')
            ->groupBy('stations.id', 'stations.code', 'stations.name')
            ->select('stations.code', 'stations.name')
            ->selectRaw('COUNT(slots.id) as empty_slots')
            ->orderByDesc('empty_slots')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'empty_slots' => (int) $row->empty_slots,
            ])
            ->all();
    }
}
