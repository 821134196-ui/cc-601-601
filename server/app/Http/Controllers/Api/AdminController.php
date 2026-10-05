<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\DeviceEvent;
use App\Models\Order;
use App\Models\Umbrella;
use App\Services\DomainException;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\ReconcileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct(
        private ReconcileService $reconcile,
        private PricingService $pricing,
        private PaymentService $payments,
    ) {}

    /** 按伞号查看雨伞当前位置与状态 */
    public function umbrella(string $code): JsonResponse
    {
        $umbrella = Umbrella::where('code', $code)->with('slot.cabinet.station')->firstOrFail();

        $activeOrder = Order::where('umbrella_id', $umbrella->id)
            ->whereIn('status', [Order::STATUS_DISPENSING, Order::STATUS_BORROWED, Order::STATUS_RETURNING])
            ->latest()
            ->first();

        $events = DeviceEvent::where('umbrella_id', $umbrella->id)
            ->orderByDesc('id')->limit(20)->get()
            ->map(fn (DeviceEvent $event) => [
                'type' => $event->type,
                'result' => $event->result,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'cabinet_code' => $event->cabinet->code,
            ]);

        $location = null;
        if ($umbrella->slot) {
            $location = [
                'type' => 'in_slot',
                'station' => $umbrella->slot->cabinet->station->name,
                'slot_label' => $umbrella->slot->label,
            ];
        } elseif ($activeOrder) {
            $location = [
                'type' => 'with_user',
                'user_name' => $activeOrder->user_name,
                'order_no' => $activeOrder->order_no,
                'borrowed_at' => $activeOrder->borrowed_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'umbrella' => [
                'code' => $umbrella->code,
                'status' => $umbrella->status,
                'location' => $location,
            ],
            'events' => $events,
        ]);
    }

    /**
     * 雨伞运维操作:清洁 / 维修 / 恢复上架 / 登记丢失。
     * 清洁与维修中的雨伞保留在柜位但不可借出。
     */
    public function umbrellaAction(string $code, Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:start_cleaning,start_repair,restore,mark_lost'],
        ]);

        $umbrella = Umbrella::where('code', $code)->firstOrFail();

        $result = DB::transaction(function () use ($umbrella, $data) {
            $umbrella = Umbrella::whereKey($umbrella->id)->lockForUpdate()->firstOrFail();

            return match ($data['action']) {
                'start_cleaning' => $this->toMaintenance($umbrella, Umbrella::STATUS_CLEANING),
                'start_repair' => $this->toMaintenance($umbrella, Umbrella::STATUS_REPAIR),
                'restore' => $this->restore($umbrella),
                'mark_lost' => $this->markLost($umbrella),
            };
        });

        return response()->json($result);
    }

    private function toMaintenance(Umbrella $umbrella, string $status): array
    {
        if ($umbrella->status !== Umbrella::STATUS_IN_SLOT) {
            throw new DomainException('invalid_state', '只有在架雨伞才能登记清洁/维修', 409);
        }
        $umbrella->status = $status;
        $umbrella->save();

        return ['umbrella' => ['code' => $umbrella->code, 'status' => $umbrella->status]];
    }

    private function restore(Umbrella $umbrella): array
    {
        if (! in_array($umbrella->status, [Umbrella::STATUS_CLEANING, Umbrella::STATUS_REPAIR], true)) {
            throw new DomainException('invalid_state', '只有清洁/维修中的雨伞才能恢复上架', 409);
        }
        $umbrella->status = Umbrella::STATUS_IN_SLOT;
        $umbrella->save();

        return ['umbrella' => ['code' => $umbrella->code, 'status' => $umbrella->status]];
    }

    /**
     * 登记丢失:伞在用户手中未归还 → 订单按当前时长计费并加收丢失赔偿金后关闭。
     */
    private function markLost(Umbrella $umbrella): array
    {
        if ($umbrella->status !== Umbrella::STATUS_BORROWED) {
            throw new DomainException('invalid_state', '只有借出中的雨伞才能登记丢失', 409);
        }

        $order = Order::where('umbrella_id', $umbrella->id)
            ->whereIn('status', [Order::STATUS_BORROWED, Order::STATUS_RETURNING])
            ->lockForUpdate()
            ->first();

        $umbrella->status = Umbrella::STATUS_LOST;
        $umbrella->slot_id = null;
        $umbrella->save();

        if ($order) {
            // 释放可能存在的还伞预留
            $session = $order->activeReturnSession;
            if ($session) {
                $session->slot?->update(['reserved_by_return_id' => null]);
                $session->update(['status' => \App\Models\ReturnSession::STATUS_CANCELLED]);
            }

            $order->status = Order::STATUS_COMPLETED;
            $order->returned_at = null;
            $order->fee_cents = $this->pricing->quoteForOrder($order)
                + (int) config('umbrella.lost_fee_cents', 2000);
            $order->save();

            $this->payments->createForOrder($order);
        }

        return [
            'umbrella' => ['code' => $umbrella->code, 'status' => $umbrella->status],
            'order_no' => $order?->order_no,
            'fee_cents' => $order?->fee_cents,
        ];
    }

    /** 柜机离线/上线切换(模拟网络中断与恢复) */
    public function cabinetConnectivity(string $code, Request $request): JsonResponse
    {
        $data = $request->validate(['online' => ['required', 'boolean']]);

        $cabinet = Cabinet::where('code', $code)->firstOrFail();
        $cabinet->online = $data['online'];
        $cabinet->save();

        return response()->json([
            'cabinet_code' => $cabinet->code,
            'online' => $cabinet->online,
        ]);
    }

    /** 事件记录:核对离线补传与日常事件的应用结果 */
    public function events(Request $request): JsonResponse
    {
        $query = DeviceEvent::with(['cabinet', 'slot', 'umbrella'])->orderByDesc('id')->limit(100);

        if ($request->filled('cabinet_code')) {
            $cabinet = Cabinet::where('code', $request->query('cabinet_code'))->first();
            $query->where('cabinet_id', $cabinet?->id ?? 0);
        }

        return response()->json([
            'events' => $query->get()->map(fn (DeviceEvent $event) => [
                'event_uid' => $event->event_uid,
                'cabinet_code' => $event->cabinet->code,
                'type' => $event->type,
                'slot_label' => $event->slot?->label,
                'umbrella_code' => $event->umbrella?->code,
                'device_seq' => $event->device_seq,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'received_at' => $event->received_at->toIso8601String(),
                'result' => $event->result,
                'note' => $event->note,
            ]),
        ]);
    }

    /** 对账:柜位占用、雨伞位置、订单状态三方核对 */
    public function reconcile(): JsonResponse
    {
        return response()->json($this->reconcile->report());
    }

    /** 柜机列表 */
    public function cabinets(): JsonResponse
    {
        return response()->json([
            'cabinets' => Cabinet::with('station')->get()->map(fn (Cabinet $cabinet) => [
                'code' => $cabinet->code,
                'station' => $cabinet->station->name,
                'online' => $cabinet->online,
                'last_seen_at' => $cabinet->last_seen_at?->toIso8601String(),
            ]),
        ]);
    }
}
