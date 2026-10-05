<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ExpirationService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orders,
        private ExpirationService $expiration,
    ) {}

    /** 发起借伞:分配雨伞并下发开门命令,等待柜门实际放出 */
    public function store(Request $request): JsonResponse
    {
        $this->expiration->sweep();

        $data = $request->validate([
            'user_name' => ['required', 'string', 'max:50'],
            'station_code' => ['required', 'string'],
        ]);

        ['order' => $order, 'slot' => $slot] = $this->orders->createBorrowOrder(
            $data['user_name'],
            $data['station_code'],
        );

        return response()->json([
            'order' => $this->serialize($order),
            'slot_label' => $slot->label,
            'message' => "请到 {$slot->label} 柜门取伞",
        ], 201);
    }

    /** 订单详情(前端轮询:状态与柜门事件联动) */
    public function show(string $orderNo): JsonResponse
    {
        $this->expiration->sweep();

        $order = Order::where('order_no', $orderNo)->firstOrFail();

        return response()->json(['order' => $this->serialize($order)]);
    }

    /** 我的订单列表(按用户名查询) */
    public function index(Request $request): JsonResponse
    {
        $this->expiration->sweep();

        $query = Order::with(['umbrella', 'borrowStation', 'returnStation', 'payment'])
            ->orderByDesc('id')
            ->limit(50);

        if ($request->filled('user_name')) {
            $query->where('user_name', $request->query('user_name'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json([
            'orders' => $query->get()->map(fn (Order $order) => $this->serialize($order)),
        ]);
    }

    private function serialize(Order $order): array
    {
        $order->loadMissing(['umbrella', 'borrowStation', 'returnStation', 'payment', 'activeReturnSession']);

        return [
            'order_no' => $order->order_no,
            'user_name' => $order->user_name,
            'status' => $order->status,
            'umbrella_code' => $order->umbrella->code,
            'borrow_station' => $order->borrowStation->name,
            'borrow_station_code' => $order->borrowStation->code,
            'return_station' => $order->returnStation?->name,
            'borrowed_at' => $order->borrowed_at?->toIso8601String(),  // 计费起点
            'returned_at' => $order->returned_at?->toIso8601String(),
            'fee_cents' => $order->fee_cents,
            'payment' => $order->payment ? [
                'payment_no' => $order->payment->payment_no,
                'amount_cents' => $order->payment->amount_cents,
                'status' => $order->payment->status,
                'paid_at' => $order->payment->paid_at?->toIso8601String(),
            ] : null,
            'active_return_session' => $order->activeReturnSession ? [
                'session_no' => $order->activeReturnSession->session_no,
                'station_id' => $order->activeReturnSession->station_id,
                'slot_label' => $order->activeReturnSession->slot?->label,
            ] : null,
            'created_at' => $order->created_at->toIso8601String(),
        ];
    }
}
