<?php

namespace App\Services;

use App\Models\DoorCommand;
use App\Models\Order;
use App\Models\Slot;
use App\Models\Station;
use App\Models\Umbrella;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 借还编排：负责"指令阶段"——预留柜位、创建订单、向柜机下发开门指令。
 * 状态最终生效一律以柜机事件为准（见 EventProcessor）。
 */
class UmbrellaService
{
    /**
     * 发起借伞：挑选一把可借雨伞，预留柜位并指令柜机开门。
     * 订单在柜门实际放出雨伞（umbrella_dispensed 事件）时才进入借用中并开始计费。
     */
    public function borrow(int $stationId, string $userName): Order
    {
        return DB::transaction(function () use ($stationId, $userName) {
            $station = Station::whereKey($stationId)->lockForUpdate()->firstOrFail();

            // 只挑"在柜且状态可借"的伞：清洁/维修/丢失中的一律不可借
            $slot = Slot::where('station_id', $station->id)
                ->where('status', 'occupied')
                ->whereHas('umbrella', fn ($q) => $q->where('status', Umbrella::STATUS_IN_SLOT))
                ->lockForUpdate()
                ->orderBy('label')
                ->first();

            if (! $slot) {
                throw new \RuntimeException('该站点暂无可借雨伞');
            }

            $slot->update(['status' => 'reserved']);

            $order = Order::create([
                'order_no' => 'O'.now()->format('Ymd').strtoupper(Str::random(8)),
                'user_name' => $userName,
                'umbrella_id' => $slot->umbrella_id,
                'borrow_station_id' => $station->id,
                'status' => Order::STATUS_DISPENSING,
            ]);

            DoorCommand::create([
                'station_id' => $station->id,
                'slot_id' => $slot->id,
                'order_id' => $order->id,
                'purpose' => 'borrow',
                'status' => 'pending',
            ]);

            return $order;
        });
    }

    /**
     * 发起还伞：分配一个空柜位并指令柜机开门。
     * 站点已满时抛出 StationFullException（携带可选站点），订单保持借用中、原计费起点不变。
     */
    public function initiateReturn(Order $order, int $stationId): DoorCommand
    {
        return DB::transaction(function () use ($order, $stationId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== Order::STATUS_ACTIVE) {
                throw new \RuntimeException('订单不在借用中，无法归还');
            }

            $station = Station::whereKey($stationId)->lockForUpdate()->firstOrFail();
            $slot = Slot::where('station_id', $station->id)->where('status', 'empty')
                ->lockForUpdate()->orderBy('label')->first();

            if (! $slot) {
                throw new StationFullException($this->stationsWithEmptySlots($station->id));
            }

            $slot->update(['status' => 'reserved']);
            $order->update(['status' => Order::STATUS_RETURNING]);

            return DoorCommand::create([
                'station_id' => $station->id,
                'slot_id' => $slot->id,
                'order_id' => $order->id,
                'purpose' => 'return',
                'status' => 'pending',
            ]);
        });
    }

    /** 取消待取伞的订单（如超时未取）：释放预留柜位，雨伞恢复可借 */
    public function cancelDispensing(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->status === Order::STATUS_DISPENSING, 422, '仅待取伞订单可取消');

            $command = DoorCommand::where('order_id', $order->id)->where('purpose', 'borrow')
                ->where('status', 'pending')->firstOrFail();
            $command->update(['status' => 'expired']);
            Slot::whereKey($command->slot_id)->update(['status' => 'occupied']);
            $order->update(['status' => Order::STATUS_CANCELLED]);

            return $order;
        });
    }

    /** 取消进行中的归还（如用户改去别的站）：释放预留空位，订单回到借用中，计费起点不变 */
    public function cancelReturn(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->status === Order::STATUS_RETURNING, 422, '订单不在归还流程中');

            $command = DoorCommand::where('order_id', $order->id)->where('purpose', 'return')
                ->where('status', 'pending')->firstOrFail();
            $command->update(['status' => 'expired']);
            Slot::whereKey($command->slot_id)->update(['status' => 'empty']);
            $order->update(['status' => Order::STATUS_ACTIVE]);

            return $order;
        });
    }

    /** 除排除站点外，当前还有空柜位的站点（满柜换站推荐） */
    public function stationsWithEmptySlots(?int $excludeStationId = null): array
    {
        return Station::query()
            ->when($excludeStationId, fn ($q) => $q->where('id', '!=', $excludeStationId))
            ->whereHas('slots', fn ($q) => $q->where('status', 'empty'))
            ->withCount(['slots as empty_slots' => fn ($q) => $q->where('status', 'empty')])
            ->orderBy('code')
            ->get(['id', 'name', 'code', 'address'])
            ->toArray();
    }
}
