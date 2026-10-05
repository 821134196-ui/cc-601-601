<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    /**
     * 模拟支付:用户在"支付网关"付款,网关随即把回执推送给系统。
     * times>1 模拟网关重试(同一回执重复推送),验证重复回执不重复收费。
     */
    public function mockPay(string $paymentNo, Request $request): JsonResponse
    {
        $payment = Payment::where('payment_no', $paymentNo)->firstOrFail();
        $times = min(5, max(1, (int) $request->input('times', 1)));

        $result = $this->payments->mockCheckout($payment, $times);

        return response()->json([
            'payment' => $this->serialize($payment->fresh()),
            'receipt_no' => $result['receipt_no'],
            'deliveries' => $result['deliveries'],
        ]);
    }

    /** 支付回执 webhook(本地模拟网关的回调入口) */
    public function webhook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'receipt_no' => ['required', 'string'],
            'payment_no' => ['required', 'string'],
            'amount_cents' => ['required', 'integer', 'min:0'],
            'paid_at' => ['nullable', 'string'],
            'sign' => ['nullable', 'string'],
        ]);

        return response()->json($this->payments->handleReceipt($data));
    }

    public function show(string $paymentNo): JsonResponse
    {
        $payment = Payment::where('payment_no', $paymentNo)->firstOrFail();

        return response()->json(['payment' => $this->serialize($payment)]);
    }

    private function serialize(Payment $payment): array
    {
        return [
            'payment_no' => $payment->payment_no,
            'order_id' => $payment->order_id,
            'amount_cents' => $payment->amount_cents,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
    }
}
