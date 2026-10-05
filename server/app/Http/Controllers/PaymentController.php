<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    /**
     * 本地模拟支付的收银台：为已完成订单生成一笔支付回执。
     * 返回 transaction_id，前端/网关凭回执回调 confirm。
     */
    public function checkout(Request $request)
    {
        $data = $request->validate(['order_id' => 'required|exists:orders,id']);
        $order = Order::findOrFail($data['order_id']);

        if ($order->status !== Order::STATUS_COMPLETED) {
            return response()->json(['message' => '订单未完成，无需支付'], 422);
        }
        if ($order->payment_status === 'paid') {
            return response()->json(['message' => '订单已支付'], 422);
        }

        // 模拟支付网关：生成交易号与回执
        return [
            'transaction_id' => 'TXN-'.strtoupper(Str::random(16)),
            'order_id' => $order->id,
            'amount_cents' => $order->fee_cents,
        ];
    }

    /**
     * 支付回执回调（模拟网关通知）。transaction_id 幂等：
     * 重复回执返回原记录，不会再次收费。
     */
    public function confirm(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'transaction_id' => 'required|string|max:100',
            'amount_cents' => 'required|integer|min:0',
        ]);

        try {
            $result = $this->payments->recordReceipt(
                $data['order_id'], $data['transaction_id'], $data['amount_cents'],
                ['channel' => 'mock', 'confirmed_at' => now()->toIso8601String()]
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'duplicate' => $result['duplicate'],
            'payment' => $result['payment'],
        ], $result['duplicate'] ? 200 : 201);
    }

    public function index()
    {
        return Payment::orderByDesc('id')->limit(50)->get();
    }
}
