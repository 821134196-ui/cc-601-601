<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\StationFullException;
use App\Services\UmbrellaService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private UmbrellaService $service) {}

    /** 我的订单（按用户名查询，演示系统免登录） */
    public function index(Request $request)
    {
        $request->validate(['user' => 'required|string']);

        return Order::with(['umbrella:id,code', 'borrowStation:id,name,code', 'returnStation:id,name,code'])
            ->where('user_name', $request->query('user'))
            ->orderByDesc('id')->limit(20)->get()
            ->map(fn (Order $o) => $this->serialize($o));
    }

    /** 发起借伞：订单进入"待取伞"，柜门打开；以柜门实际放伞事件为借用成功 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'user_name' => 'required|string|max:50',
        ]);

        try {
            $order = $this->service->borrow($data['station_id'], $data['user_name']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($order->fresh(['umbrella', 'borrowStation'])), 201);
    }

    public function show(Order $order)
    {
        return $this->serialize($order->load(['umbrella', 'borrowStation', 'returnStation']));
    }

    /**
     * 发起还伞：分配空柜位并开门。
     * 站点已满 → 409 + 可选站点列表；订单保持借用中，原计费起点保留。
     */
    public function returnOrder(Request $request, Order $order)
    {
        $data = $request->validate(['station_id' => 'required|exists:stations,id']);

        try {
            $this->service->initiateReturn($order, $data['station_id']);
        } catch (StationFullException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'station_full' => true,
                'alternatives' => $e->alternatives,
                'order' => $this->serialize($order->fresh(['umbrella', 'borrowStation'])),
            ], 409);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->serialize($order->fresh(['umbrella', 'borrowStation']));
    }

    /** 取消待取伞（超时未取）：释放柜位 */
    public function cancelBorrow(Order $order)
    {
        return $this->serialize($this->service->cancelDispensing($order));
    }

    /** 取消进行中的归还（改去别的站）：订单回到借用中，计费起点不变 */
    public function cancelReturn(Order $order)
    {
        return $this->serialize($this->service->cancelReturn($order));
    }

    private function serialize(Order $o): array
    {
        return [
            'id' => $o->id,
            'order_no' => $o->order_no,
            'user_name' => $o->user_name,
            'status' => $o->status,
            'umbrella_code' => $o->umbrella?->code,
            'borrow_station' => $o->borrowStation?->only(['id', 'name', 'code']),
            'return_station' => $o->returnStation?->only(['id', 'name', 'code']),
            'borrowed_at' => $o->borrowed_at?->toIso8601String(),
            'returned_at' => $o->returned_at?->toIso8601String(),
            'fee_cents' => $o->fee_cents,
            'payment_status' => $o->payment_status,
        ];
    }
}
