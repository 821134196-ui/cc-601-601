<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Umbrella;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /** 按伞号查询位置与状态（管理端） */
    public function findUmbrella(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $umbrella = Umbrella::with(['station:id,name,code', 'slot:id,label'])
            ->where('code', strtoupper(trim($request->query('code'))))->first();

        if (! $umbrella) {
            return response()->json(['message' => '伞号不存在'], 404);
        }

        $activeOrder = null;
        if ($umbrella->status === Umbrella::STATUS_BORROWED) {
            $activeOrder = Order::with('borrowStation:id,name,code')
                ->where('umbrella_id', $umbrella->id)
                ->whereIn('status', [Order::STATUS_ACTIVE, Order::STATUS_RETURNING, Order::STATUS_DISPENSING])
                ->latest()->first();
        }

        return [
            'umbrella' => $umbrella,
            'location' => $this->locationOf($umbrella),
            'active_order' => $activeOrder ? [
                'order_no' => $activeOrder->order_no,
                'user_name' => $activeOrder->user_name,
                'status' => $activeOrder->status,
                'borrowed_at' => $activeOrder->borrowed_at?->toIso8601String(),
                'borrow_station' => $activeOrder->borrowStation?->name,
            ] : null,
        ];
    }

    /** 全部雨伞一览 */
    public function umbrellas()
    {
        return Umbrella::with(['station:id,name,code', 'slot:id,label'])->orderBy('code')->get()
            ->map(fn ($u) => $u->toArray() + ['location' => $this->locationOf($u)]);
    }

    /**
     * 雨伞状态处置：清洁 / 维修 / 丢失 / 恢复可用。
     * 维修与清洁中的伞不会被借出（借伞只挑 in_slot 状态）。
     */
    public function updateUmbrella(Request $request, Umbrella $umbrella)
    {
        $data = $request->validate(['status' => 'required|in:cleaning,repair,lost,in_slot']);

        return DB::transaction(function () use ($umbrella, $data) {
            $umbrella = Umbrella::whereKey($umbrella->id)->lockForUpdate()->firstOrFail();
            $target = $data['status'];

            if (in_array($target, ['cleaning', 'repair']) && $umbrella->status !== Umbrella::STATUS_IN_SLOT) {
                return response()->json(['message' => '只有柜内的伞才能标记清洁/维修'], 422);
            }
            if ($target === 'in_slot' && ! in_array($umbrella->status, ['cleaning', 'repair'])) {
                return response()->json(['message' => '仅清洁/维修中的伞可恢复可用'], 422);
            }
            if ($target === 'lost') {
                // 标记丢失：若在柜则释放柜位（物理上伞已不在）
                if ($umbrella->slot_id) {
                    $umbrella->slot()->update(['status' => 'empty', 'umbrella_id' => null]);
                    $umbrella->slot_id = null;
                }
            }

            $umbrella->status = $target;
            $umbrella->save();

            return $umbrella->fresh(['station', 'slot'])->toArray() + ['location' => $this->locationOf($umbrella->fresh(['station', 'slot']))];
        });
    }

    /** 订单一览（管理端） */
    public function orders()
    {
        return Order::with(['umbrella:id,code', 'borrowStation:id,name', 'returnStation:id,name'])
            ->orderByDesc('id')->limit(100)->get();
    }

    private function locationOf(Umbrella $u): string
    {
        return match ($u->status) {
            'in_slot', 'cleaning', 'repair' => ($u->station?->name ?? '未知站点').' / 柜位 '.($u->slot?->label ?? '?'),
            'borrowed' => '借出中（用户持有）',
            'lost' => '已丢失',
            default => '未知',
        };
    }
}
