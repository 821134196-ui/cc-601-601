<?php

namespace App\Services;

use App\Models\CabinetCommand;
use App\Models\Order;
use App\Models\Slot;
use App\Models\Station;
use App\Models\Umbrella;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * 发起借伞:分配一把可借雨伞并下发开门命令。
     *
     * 注意:此时订单只是 dispensing —— 借用是否成功以柜门实际放出雨伞的
     * 设备事件(umbrella_dispensed)为准,计费起点也取该事件的发生时间。
     *
     * @return array{order: Order, slot: Slot}
     */
    public function createBorrowOrder(string $userName, string $stationCode): array
    {
        return DB::transaction(function () use ($userName, $stationCode) {
            $station = Station::where('code', $stationCode)->with('cabinet')->first();
            if (! $station || ! $station->cabinet) {
                throw new DomainException('station_not_found', '站点不存在', 404);
            }

            // 只分配"在架可借"的雨伞:维修/清洁/已借出/正在放出的都不可借
            $slot = Slot::where('slots.cabinet_id', $station->cabinet->id)
                ->where('slots.state', Slot::STATE_OCCUPIED)
                ->join('umbrellas', 'umbrellas.id', '=', 'slots.umbrella_id')
                ->where('umbrellas.status', Umbrella::STATUS_IN_SLOT)
                ->orderBy('slots.label')
                ->select('slots.*')
                ->lockForUpdate()
                ->first();

            if (! $slot) {
                throw new DomainException('no_umbrella', '该站暂无可借雨伞', 409);
            }

            $umbrella = Umbrella::whereKey($slot->umbrella_id)->lockForUpdate()->firstOrFail();
            if ($umbrella->status !== Umbrella::STATUS_IN_SLOT) {
                // 并发下状态已变化,拒绝本次分配
                throw new DomainException('no_umbrella', '该站暂无可借雨伞', 409);
            }

            // 占位:防止同一把伞被两笔订单同时分配
            $umbrella->status = Umbrella::STATUS_DISPATCHING;
            $umbrella->save();

            $order = Order::create([
                'order_no' => $this->generateNo('O'),
                'user_name' => $userName,
                'umbrella_id' => $umbrella->id,
                'borrow_station_id' => $station->id,
                'status' => Order::STATUS_DISPENSING,
            ]);

            CabinetCommand::create([
                'cabinet_id' => $station->cabinet->id,
                'slot_id' => $slot->id,
                'type' => CabinetCommand::TYPE_OPEN_DOOR,
                'status' => CabinetCommand::STATUS_PENDING,
                'ref_type' => 'order',
                'ref_id' => $order->id,
                'payload' => ['umbrella_code' => $umbrella->code, 'slot_label' => $slot->label],
                'expires_at' => now()->addSeconds((int) config('umbrella.borrow_command_ttl_seconds', 120)),
            ]);

            return ['order' => $order->fresh(), 'slot' => $slot];
        });
    }

    public function generateNo(string $prefix): string
    {
        return $prefix.now()->format('ymd').strtoupper(Str::random(6));
    }
}
